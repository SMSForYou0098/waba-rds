<?php

namespace App\Jobs;

use App\Events\CampaignCompleted;
use App\Events\CampaignProgress;
use App\Models\Campaign\Campaign;
use App\Services\Meta\MetaMessageSender;
use App\Services\Meta\TokenBucket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class SendCampaignMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public int $timeout = 30;

    public function __construct(
        public int $campaignReportId,
        public string $whatsappPhoneId
    ) {
        $this->onQueue('campaign-meta');
    }

    public function backoff(): array
    {
        return [2, 4, 8, 16, 30, 30, 30, 30];
    }

    public function handle(TokenBucket $bucket, MetaMessageSender $sender): void
    {
        $claimed = DB::table('campaign_reports')
            ->where('id', $this->campaignReportId)
            ->where('status', 'pending')
            ->update([
                'status' => 'sending',
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $row = DB::table('campaign_reports')->where('id', $this->campaignReportId)->first();
        if (! $row) {
            return;
        }

        $campaign = Campaign::query()->with('user.userConfig')->find($row->campaign_id);
        if (! $campaign || ! $campaign->user?->userConfig?->meta_access_token) {
            $this->markFailedRow((int) $row->id, 'CONFIG', 'Campaign user config missing Meta token.');
            $this->broadcastProgressAndCompletion((int) $row->campaign_id, false, true);

            return;
        }

        $cbKey = 'meta:cb:'.$this->whatsappPhoneId.':fails';
        $fails = (int) Redis::get($cbKey);
        if ($fails > 50) {
            $this->revertToPending();
            $this->release(15);

            return;
        }

        $waitMs = $bucket->acquire($this->whatsappPhoneId);
        if ($waitMs > 0) {
            if ($waitMs <= 1500) {
                usleep($waitMs * 1000);
            } else {
                $this->revertToPending();
                $this->release((int) ceil($waitMs / 1000));

                return;
            }
        }

        $payload = json_decode((string) ($row->payload ?? '{}'), true);
        if (! is_array($payload) || $payload === []) {
            $this->markFailedRow((int) $row->id, 'PAYLOAD', 'Missing or invalid campaign payload.');
            $this->broadcastProgressAndCompletion((int) $row->campaign_id, false, true);

            return;
        }

        $result = $sender->send(
            $this->whatsappPhoneId,
            (string) $campaign->user->userConfig->meta_access_token,
            $payload
        );

        if ($result['ok']) {
            DB::table('campaign_reports')->where('id', $row->id)->update([
                'message_id' => $result['wamid'],
                'status' => 'sent',
                'attempts' => DB::raw('attempts + 1'),
                'last_error_code' => null,
                'last_error' => null,
                'sent_at' => now(),
                'updated_at' => now(),
            ]);

            Redis::del($cbKey);
            $this->broadcastProgressAndCompletion((int) $row->campaign_id, true, false);

            return;
        }

        DB::table('campaign_reports')->where('id', $row->id)->update([
            'attempts' => DB::raw('attempts + 1'),
            'last_error_code' => $result['code'],
            'last_error' => mb_substr((string) $result['error'], 0, 1000),
            'updated_at' => now(),
        ]);

        $attemptsNow = ((int) ($row->attempts ?? 0)) + 1;
        $hasRetriesLeft = $attemptsNow < $this->tries;

        if ($result['retryable'] && $hasRetriesLeft) {
            if (($result['http'] ?? 0) >= 500) {
                Redis::incr($cbKey);
                Redis::expire($cbKey, 60);
            }

            $this->revertToPending();
            $delay = $result['code'] === '130429'
                ? max(5, (int) ($this->backoff()[$attemptsNow - 1] ?? 30))
                : (int) ($this->backoff()[$attemptsNow - 1] ?? 30);
            $this->release($delay);

            return;
        }

        DB::table('campaign_reports')->where('id', $row->id)->update([
            'status' => 'failed',
            'updated_at' => now(),
        ]);

        Log::warning('Campaign report failed permanently', [
            'report_id' => $row->id,
            'campaign_id' => $row->campaign_id,
            'code' => $result['code'],
            'error' => $result['error'],
        ]);

        $this->broadcastProgressAndCompletion((int) $row->campaign_id, false, true);
    }

    public function failed(Throwable $e): void
    {
        DB::table('campaign_reports')
            ->where('id', $this->campaignReportId)
            ->whereIn('status', ['sending', 'pending'])
            ->update([
                'status' => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'updated_at' => now(),
            ]);
    }

    private function revertToPending(): void
    {
        DB::table('campaign_reports')
            ->where('id', $this->campaignReportId)
            ->update([
                'status' => 'pending',
                'updated_at' => now(),
            ]);
    }

    private function markFailedRow(int $rowId, string $code, string $message): void
    {
        DB::table('campaign_reports')->where('id', $rowId)->update([
            'status' => 'failed',
            'attempts' => DB::raw('attempts + 1'),
            'last_error_code' => $code,
            'last_error' => mb_substr($message, 0, 1000),
            'updated_at' => now(),
        ]);
    }

    private function broadcastProgressAndCompletion(int $campaignId, bool $sentIncrement, bool $failedIncrement): void
    {
        $stateKey = 'campaign:state:'.$campaignId;
        $campaignIdStr = (string) $campaignId;
        $total = (int) Redis::hget($stateKey, 'total');
        if ($total <= 0) {
            $cachedContext = Cache::get('campaign_send:'.$campaignIdStr);
            $totalFromCache = is_array($cachedContext) ? (int) ($cachedContext['total_recipients'] ?? 0) : 0;
            $total = $totalFromCache > 0
                ? $totalFromCache
                : (int) DB::table('campaign_reports')->where('campaign_id', $campaignId)->count();
            Redis::hset($stateKey, 'total', $total);
            Redis::expire($stateKey, 172800);
        }

        if ($total <= 0) {
            return;
        }

        if ($sentIncrement) {
            Redis::hincrby($stateKey, 'sent', 1);
        }
        if ($failedIncrement) {
            Redis::hincrby($stateKey, 'failed', 1);
        }
        if ($sentIncrement || $failedIncrement) {
            Redis::hincrby($stateKey, 'processed', 1);
        }

        $sent = (int) Redis::hget($stateKey, 'sent');
        $failed = (int) Redis::hget($stateKey, 'failed');
        $processed = (int) Redis::hget($stateKey, 'processed');

        $interval = 5;
        $progressCandidate = $sent === 1
            ? 1
            : (int) (floor($sent / $interval) * $interval);
        $lastProgressSent = (int) Redis::hget($stateKey, 'last_progress_sent');
        if ($progressCandidate > $lastProgressSent) {
            Redis::hset($stateKey, 'last_progress_sent', $progressCandidate);
            $userId = $this->campaignUserId($campaignId, $stateKey);
            if ($userId !== null) {
            $percent = $total > 0 ? round(($sent / $total) * 100, 2) : 0.0;
                event(new CampaignProgress($userId, $campaignId, $sent, $total, $percent));
            }
        }

        if ($processed >= $total) {
            $lockKey = 'campaign:completed:'.$campaignIdStr;
            if (Cache::add($lockKey, 1, now()->addMinutes(10))) {
                $userId = $this->campaignUserId($campaignId, $stateKey);
                if ($userId !== null) {
                    event(new CampaignCompleted($userId, $campaignId, $sent, $failed));
                }
                Cache::forget('campaign_send:'.$campaignIdStr);
                Redis::del($stateKey);
            }
        }
    }

    private function campaignUserId(int $campaignId, string $stateKey): ?int
    {
        $cached = Redis::hget($stateKey, 'user_id');
        if ($cached !== null && $cached !== false && $cached !== '') {
            return (int) $cached;
        }

        $campaign = Campaign::query()->find($campaignId);
        if (! $campaign) {
            return null;
        }

        $userId = (int) $campaign->user_id;
        Redis::hset($stateKey, 'user_id', $userId);

        return $userId;
    }
}
