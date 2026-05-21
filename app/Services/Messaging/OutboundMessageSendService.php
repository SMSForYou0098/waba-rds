<?php

namespace App\Services\Messaging;

use App\Exceptions\Billing\InsufficientCreditsException;
use App\Exceptions\Billing\MessageAlreadyBilledException;
use App\Exceptions\Billing\PricingNotConfiguredException;
use App\Models\Billing\Balance;
use App\Models\Report\OutReport;
use App\Models\User;
use App\Services\Billing\MessagePricingResolver;
use App\Services\Meta\MetaMessageSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OutboundMessageSendService
{
    public function __construct(
        private readonly MetaMessageSender $sender,
        private readonly MessagePricingResolver $pricingResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Meta send-message body (must include `to`)
     * @return array{
     *     ok: bool,
     *     wamid: ?string,
     *     out_report_id: ?int,
     *     deducted: ?float,
     *     balance_after: ?float,
     *     error: ?string,
     *     code: ?string,
     *     http: ?int
     * }
     */
    public function sendAndBill(
        User $user,
        array $payload,
        string $phoneId,
        string $token,
        ?string $wabaId = null,
        ?string $billingCategory = null,
    ): array {
        $user->loadMissing(['latestBalance', 'userConfig']);

        $pricing = $this->pricingResolver->resolve($user, $payload, $wabaId, $token, $billingCategory);

        if (! $pricing['pricing_found']) {
            throw new PricingNotConfiguredException($pricing['message'] ?? 'Pricing is not configured for this account.');
        }

        $unitPrice = (float) $pricing['unit_price'];
        $currentBalance = (float) ($user->latestBalance?->total_credits ?? 0.0);

        if ($currentBalance < $unitPrice) {
            throw new InsufficientCreditsException($currentBalance, $unitPrice);
        }

        $result = $this->sender->send($phoneId, $token, $payload);

        if (! $result['ok'] || empty($result['wamid'])) {
            return [
                'ok' => false,
                'wamid' => null,
                'out_report_id' => null,
                'deducted' => null,
                'balance_after' => null,
                'error' => $result['error'] ?? 'Message send failed',
                'code' => $result['code'],
                'http' => $result['http'] ?? 500,
            ];
        }

        $wamid = (string) $result['wamid'];

        $existing = OutReport::query()
            ->where('user_id', $user->id)
            ->where('status_id', $wamid)
            ->first();

        if ($existing) {
            if ($this->isAlreadyBilled($existing)) {
                throw new MessageAlreadyBilledException($wamid, (int) $existing->id);
            }
        }

        try {
            $billing = $this->persistOutReportAndDeduction(
                $user,
                $payload,
                $phoneId,
                $wamid,
                $unitPrice,
                (string) $pricing['category']
            );
        } catch (\Throwable $e) {
            Log::error('OutboundMessageSendService billing failed after Meta success', [
                'user_id' => $user->id,
                'wamid' => $wamid,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'wamid' => $wamid,
                'out_report_id' => null,
                'deducted' => null,
                'balance_after' => null,
                'error' => 'Message was sent but billing could not be recorded. Contact support.',
                'code' => 'BILLING_FAILED',
                'http' => 500,
            ];
        }

        $this->forgetBalanceCaches($user->id);

        return [
            'ok' => true,
            'wamid' => $wamid,
            'out_report_id' => $billing['out_report_id'],
            'deducted' => $billing['deducted'],
            'balance_after' => $billing['balance_after'],
            'error' => null,
            'code' => null,
            'http' => 200,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{out_report_id: int, deducted: float, balance_after: float}
     */
    private function persistOutReportAndDeduction(
        User $user,
        array $payload,
        string $phoneId,
        string $wamid,
        float $unitPrice,
        string $category,
    ): array {
        return DB::transaction(function () use ($user, $payload, $phoneId, $wamid, $unitPrice, $category): array {
            $locked = OutReport::query()
                ->where('user_id', $user->id)
                ->where('status_id', $wamid)
                ->lockForUpdate()
                ->first();

            if ($locked && $this->isAlreadyBilled($locked)) {
                throw new MessageAlreadyBilledException($wamid, (int) $locked->id);
            }

            $latestBalance = Balance::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $current = (float) ($latestBalance?->total_credits ?? 0.0);
            $required = round($unitPrice, 2);
            $newTotal = round($current - $required, 2);

            if ($newTotal < 0) {
                throw new InsufficientCreditsException($current, $required);
            }

            $recipient = preg_replace('/\D/', '', (string) ($payload['to'] ?? ''));

            if ($locked) {
                $outReport = $locked;
                $outReport->status = 'sent';
                $outReport->billable = '1';
                $outReport->category = $category;
                $outReport->save();
            } else {
                $outReport = new OutReport;
                $outReport->user_id = $user->id;
                $outReport->display_phone_number = (string) ($user->whatsapp_number ?? '');
                $outReport->phone_number_id = $phoneId;
                $outReport->status_id = $wamid;
                $outReport->recipient_id = $recipient !== '' ? $recipient : (string) ($payload['to'] ?? '');
                $outReport->status = 'sent';
                $outReport->billable = '1';
                $outReport->category = $category;
                $outReport->messaging_product = 'whatsapp';
                $outReport->save();
            }

            $reportingUserId = isset($user->reporting_user) && $user->reporting_user !== ''
                ? (int) $user->reporting_user
                : null;

            $balance = new Balance;
            $balance->user_id = $user->id;
            $balance->total_credits = $newTotal;
            $balance->new_credit = $required;
            $balance->report_id = $outReport->id;
            $balance->payment_type = (string) ($latestBalance->payment_type ?? 'cash');
            $balance->account_manager_id = $reportingUserId;
            $balance->manual_deduction = null;
            $balance->auto_deduction = 'true';
            $balance->remarks = 'Message debit wamid:'.$wamid;
            $balance->duplicate_count = 0;
            $balance->save();

            return [
                'out_report_id' => (int) $outReport->id,
                'deducted' => $required,
                'balance_after' => $newTotal,
            ];
        });
    }

    private function isAlreadyBilled(OutReport $report): bool
    {
        return (string) $report->billable === '1'
            || Balance::query()->where('report_id', $report->id)->exists();
    }

    private function forgetBalanceCaches(int $userId): void
    {
        Cache::forget("user.{$userId}.latest_balance");
        Cache::forget("user.{$userId}.credit_history");
        Cache::forget('admin.all_credit_history');
    }
}
