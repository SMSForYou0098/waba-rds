<?php

namespace App\Services\Messaging;

use App\Models\Billing\Balance;
use App\Models\Campaign\Campaign;
use App\Models\User;
use App\Services\Billing\CampaignPricingService;
use App\Services\Campaign\CampaignDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CampaignSendService
{
    public function __construct(
        protected CampaignPricingService $campaignPricingService,
        protected WhatsAppTemplateResolver $templateResolver,
        protected CampaignDispatcher $campaignDispatcher
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(User $user, array $validated): JsonResponse
    {
        if ((int) $validated['user_id'] !== (int) $user->id) {
            return $this->jsonError('You may only send campaigns for your own account.', 403);
        }

        $user->loadMissing('userConfig');
        $config = $user->userConfig;
        if (! $config?->whatsapp_phone_id || ! $config->meta_access_token) {
            return $this->jsonError('WhatsApp is not configured for this account (missing phone ID or Meta token).', 422);
        }

        $normalizedType = strtolower((string) $validated['campaign_type']) === 'custom' ? 'custom' : 'template';

        $campaignSource = ($validated['campaign_source'] ?? 'manual') === 'excel' ? 'excel' : 'manual';

        $rowValuesMap = [];
        if ($campaignSource === 'excel') {
            $rawNumbers = array_map(fn ($row) => (string) ($row['number'] ?? ''), $validated['numbers']);
            foreach ($validated['numbers'] as $row) {
                $normalized = preg_replace('/\D/', '', (string) ($row['number'] ?? ''));
                if ($normalized !== '') {
                    $rowValuesMap[$normalized] = $row['value'] ?? [];
                }
            }
        } else {
            $rawNumbers = $validated['numbers'];
        }

        $numbers = $this->normalizeNumbers($rawNumbers);
        $invalid = $this->firstInvalidNumberLength($numbers);
        if ($invalid !== null) {
            return $this->jsonError(
                'Each phone number must be 10 or 12 digits after normalizing: invalid number '.$invalid,
                422
            );
        }

        if ($numbers === []) {
            return $this->jsonError('No valid phone numbers after normalization.', 422);
        }

        $estimate = $this->campaignPricingService->estimateBulkSend(
            (int) $validated['user_id'],
            count($numbers),
            $normalizedType,
            $normalizedType === 'template' ? ($validated['template_category'] ?? null) : null
        );

        if (! $estimate['user_found'] || ! $estimate['pricing_found'] || ! $estimate['can_proceed']) {
            return response()->json([
                'data' => [
                    'message' => $estimate['message'] ?? 'Cannot send campaign (insufficient credits or missing pricing).',
                    'balance_required' => $estimate['balance_required'],
                    'current_balance' => $estimate['current_balance'],
                ],
                'status' => 422,
            ], 422);
        }

        $templateBlocks = [];
        $resolvedLanguage = (string) ($validated['template_language'] ?? 'en_US');

        if ($normalizedType === 'template') {
            $wabaId = $config->whatsapp_business_account_id;
            if (! $wabaId) {
                return $this->jsonError('WhatsApp Business Account ID is not configured.', 422);
            }

            $resolved = $this->templateResolver->resolve(
                $wabaId,
                $config->meta_access_token,
                (string) $validated['template_name']
            );

            if (isset($resolved['error'])) {
                return $this->jsonError('Template could not be loaded: '.$resolved['error'], 422);
            }

            $templateBlocks = $resolved['blocks'];
            $resolvedLanguage = (string) ($validated['template_language'] ?? $resolved['language']);
        }

        $missingColumns = $this->missingMetaPipelineColumns();
        if ($missingColumns !== []) {
            return $this->jsonError(
                'Campaign pipeline schema is not ready. Please run migrations. Missing columns: '.implode(', ', $missingColumns),
                500
            );
        }

        try {
            $campaign = $this->persistCampaignWithDeduction(
                $user,
                $validated,
                $normalizedType,
                (float) ($estimate['balance_required'] ?? 0.0)
            );
        } catch (RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        $context = $this->buildDispatchContext($validated, $normalizedType, $templateBlocks, $resolvedLanguage, count($numbers), $campaignSource, $rowValuesMap);
        Cache::put('campaign_send:'.$campaign->id, $context, now()->addHours(48));

        $this->campaignDispatcher->start($campaign, $numbers, (string) $config->whatsapp_phone_id, $context);

        return response()->json([
            'data' => ['campaign_id' => $campaign->id],
            'status' => 200,
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $rawNumbers
     * @return list<string>
     */
    private function normalizeNumbers(array $rawNumbers): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($n) => preg_replace('/\D/', '', (string) $n),
            $rawNumbers
        ))));
    }

    /**
     * @param  list<string>  $numbers
     */
    private function firstInvalidNumberLength(array $numbers): ?string
    {
        foreach ($numbers as $num) {
            if (! in_array(strlen($num), [10, 12], true)) {
                return $num;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistCampaignWithDeduction(User $user, array $validated, string $normalizedType, float $deductAmount): Campaign
    {
        $campaign = null;

        DB::transaction(function () use (&$campaign, $user, $validated, $normalizedType, $deductAmount): void {
            $campaign = new Campaign;
            $campaign->name = (string) $validated['name'];
            $campaign->user_id = (string) $validated['user_id'];
            $campaign->template_name = $normalizedType === 'template'
                ? (string) $validated['template_name']
                : 'custom';
            $campaign->save();

            $latestBalance = Balance::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $current = (float) ($latestBalance?->total_credits ?? 0.0);
            $required = round($deductAmount, 2);
            $newTotal = round($current - $required, 2);

            if ($newTotal < 0) {
                throw new RuntimeException('Insufficient credits for this campaign size.');
            }

            $reportingUserId = isset($user->reporting_user) && $user->reporting_user !== ''
                ? (int) $user->reporting_user
                : null;

            $balance = new Balance;
            $balance->user_id = $user->id;
            $balance->total_credits = $newTotal;
            $balance->new_credit = $required;
            $balance->report_id = null;
            $balance->payment_type = (string) ($latestBalance->payment_type ?? 'cash');
            $balance->account_manager_id = $reportingUserId;
            $balance->manual_deduction = null;
            $balance->auto_deduction = 'true';
            $balance->remarks = 'Campaign debit #'.$campaign->id;
            $balance->duplicate_count = 0;
            $balance->save();
        });

        assert($campaign instanceof Campaign);

        return $campaign;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $templateBlocks
     * @param  array<string, list<mixed>>  $rowValuesMap  Keyed by normalized phone number; only populated in excel mode.
     * @return array<string, mixed>
     */
    private function buildDispatchContext(
        array $validated,
        string $normalizedType,
        array $templateBlocks,
        string $resolvedLanguage,
        int $totalRecipients,
        string $campaignSource = 'manual',
        array $rowValuesMap = []
    ): array {
        return [
            'user_id' => (int) $validated['user_id'],
            'campaign_type' => $normalizedType,
            'campaign_source' => $campaignSource,
            'template_name' => $validated['template_name'] ?? null,
            'custom_text' => $validated['custom_text'] ?? null,
            'template_language' => $resolvedLanguage,
            'template_blocks' => $templateBlocks,
            'body_values' => $validated['body_values'] ?? [],
            'row_values_map' => $rowValuesMap,
            'button_value' => $validated['button_value'] ?? [],
            'header_media_url' => $validated['header_media_url'] ?? null,
            'header_media_id' => $validated['header_media_id'] ?? null,
            'header_file_name' => $validated['header_file_name'] ?? null,
            'template_category' => $validated['template_category'] ?? null,
            'total_recipients' => $totalRecipients,
        ];
    }

    private function jsonError(string $message, int $httpStatus): JsonResponse
    {
        return response()->json([
            'data' => ['message' => $message],
            'status' => $httpStatus,
        ], $httpStatus);
    }

    /**
     * @return list<string>
     */
    private function missingMetaPipelineColumns(): array
    {
        $required = [
            'whatsapp_phone_id',
            'payload',
            'attempts',
            'last_error_code',
            'last_error',
            'sent_at',
        ];

        $missing = [];
        foreach ($required as $column) {
            if (! Schema::hasColumn('campaign_reports', $column)) {
                $missing[] = $column;
            }
        }

        return $missing;
    }
}
