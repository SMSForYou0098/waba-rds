<?php

namespace App\Services\Campaign;

use App\Jobs\CampaignFeederJob;
use App\Jobs\SendCampaignMessageJob;
use App\Models\Campaign\Campaign;
use App\Services\Messaging\WhatsAppMessagePayloadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CampaignDispatcher
{
    public function __construct(
        protected WhatsAppMessagePayloadService $payloadService
    ) {}

    /**
     * @param  list<string>  $numbers
     * @param  array<string, mixed>  $context
     */
    public function start(Campaign $campaign, array $numbers, string $whatsappPhoneId, array $context): void
    {
        $campaignId = (int) $campaign->id;
        $recipientCount = count($numbers);
        $now = now();
        $hasWhatsappPhoneId = Schema::hasColumn('campaign_reports', 'whatsapp_phone_id');
        $hasPayload = Schema::hasColumn('campaign_reports', 'payload');
        $hasAttempts = Schema::hasColumn('campaign_reports', 'attempts');
        $hasLastErrorCode = Schema::hasColumn('campaign_reports', 'last_error_code');
        $hasLastError = Schema::hasColumn('campaign_reports', 'last_error');
        $hasSentAt = Schema::hasColumn('campaign_reports', 'sent_at');

        foreach (array_chunk($numbers, 1000) as $chunk) {
            $rows = [];
            foreach ($chunk as $mobile) {
                $payload = $this->buildPayload($mobile, $context);

                $row = [
                    'campaign_id' => $campaignId,
                    'mobile_number' => $mobile,
                    'status' => 'pending',
                    'template_category' => $context['template_category'] ?? null,
                    'message_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($hasWhatsappPhoneId) {
                    $row['whatsapp_phone_id'] = $whatsappPhoneId;
                }
                if ($hasPayload) {
                    $row['payload'] = json_encode($payload);
                }
                if ($hasAttempts) {
                    $row['attempts'] = 0;
                }
                if ($hasLastErrorCode) {
                    $row['last_error_code'] = null;
                }
                if ($hasLastError) {
                    $row['last_error'] = null;
                }
                if ($hasSentAt) {
                    $row['sent_at'] = null;
                }

                $rows[] = $row;
            }

            DB::table('campaign_reports')->insertOrIgnore($rows);
        }

        $directDispatchThreshold = 5;
        if ($recipientCount <= $directDispatchThreshold) {
            DB::table('campaign_reports')
                ->where('campaign_id', $campaignId)
                ->where('status', 'pending')
                ->whereNull('message_id')
                ->whereNotNull('whatsapp_phone_id')
                ->orderBy('id')
                ->chunkById(1000, function ($reports): void {
                    foreach ($reports as $report) {
                        SendCampaignMessageJob::dispatch((int) $report->id, (string) $report->whatsapp_phone_id);
                    }
                });

            return;
        }

        if ((bool) config('services.meta.fair_feeder_enabled', true)) {
            CampaignFeederJob::dispatch();

            return;
        }

        DB::table('campaign_reports')
            ->where('campaign_id', $campaignId)
            ->where('status', 'pending')
            ->whereNull('message_id')
            ->orderBy('id')
            ->chunkById(1000, function ($reports): void {
                foreach ($reports as $report) {
                    SendCampaignMessageJob::dispatch((int) $report->id, (string) $report->whatsapp_phone_id);
                }
            });
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function buildPayload(string $mobile, array $context): array
    {
        $campaignType = (string) ($context['campaign_type'] ?? 'custom');
        $isTemplate = $campaignType === 'template';
        $templateLanguage = (string) ($context['template_language'] ?? 'en_US');
        $templateBlocks = is_array($context['template_blocks'] ?? null) ? $context['template_blocks'] : [];
        $bodyValues = ($context['campaign_source'] ?? 'manual') === 'excel'
            ? array_values((array) ($context['row_values_map'][$mobile] ?? []))
            : (is_array($context['body_values'] ?? null) ? array_values($context['body_values']) : []);
        $buttonValues = $context['button_value'] ?? [];
        if (! is_array($buttonValues)) {
            $buttonValues = $buttonValues !== null && $buttonValues !== '' ? [$buttonValues] : [];
        } else {
            $buttonValues = array_values($buttonValues);
        }

        $headerMediaUrl = $context['header_media_url'] ?? null;
        $mediaId = $context['header_media_id'] ?? null;
        $effectiveMedia = filled($mediaId) ? (string) $mediaId : (filled($headerMediaUrl) ? (string) $headerMediaUrl : null);
        $resolvedMediaType = filled($mediaId) ? 'id' : (($headerMediaUrl !== null && $headerMediaUrl !== '') && is_numeric($headerMediaUrl) ? 'id' : 'link');

        return $this->payloadService->generate(
            $mobile,
            $isTemplate ? 'template' : 'custom',
            $isTemplate ? (string) ($context['template_name'] ?? '') : null,
            $templateLanguage,
            $templateBlocks,
            $isTemplate ? null : (string) ($context['custom_text'] ?? ''),
            $bodyValues,
            $buttonValues,
            $isTemplate ? null : 'text',
            $effectiveMedia,
            $resolvedMediaType,
            isset($context['header_file_name']) ? (string) $context['header_file_name'] : null,
            []
        );
    }
}
