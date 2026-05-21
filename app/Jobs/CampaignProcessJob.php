<?php

namespace App\Jobs;

use App\Events\CampaignCompleted;
use App\Events\CampaignProgress;
use App\Models\Campaign\CampaignReport;
use App\Models\User;
use App\Services\Meta\MetaApiUrl;
use App\Services\Messaging\WhatsAppCampaignMessageSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CampaignProcessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Broadcast Reverb progress after this many recipients are processed (success or fail). */
    private const PROGRESS_INTERVAL = 5;

    public int $timeout = 7200;

    public function __construct(public int $campaignId)
    {
        $this->onQueue('campaigns');
    }

    protected function cacheKey(): string
    {
        return 'campaign_send:'.$this->campaignId;
    }

    public function handle(WhatsAppCampaignMessageSender $sender): void
    {
        $context = Cache::get($this->cacheKey());
        if (! $context || ! isset($context['user_id'])) {
            Log::warning('CampaignProcessJob missing cache context', ['campaign_id' => $this->campaignId]);

            return;
        }

        $user = User::with('userConfig')->find($context['user_id']);
        if (! $user?->userConfig?->whatsapp_phone_id || ! $user->userConfig->meta_access_token) {
            Log::error('CampaignProcessJob user config incomplete', ['user_id' => $context['user_id']]);
            Cache::forget($this->cacheKey());

            return;
        }

        if (! env('WA_API_MESSAGES')) {
            Log::error('CampaignProcessJob WA_API_MESSAGES not configured');
            Cache::forget($this->cacheKey());

            return;
        }

        $messagesApi = MetaApiUrl::messages((string) $user->userConfig->whatsapp_phone_id);
        $waToken = $user->userConfig->meta_access_token;

        $campaignIdStr = (string) $this->campaignId;
        $totalRecipients = (int) ($context['total_recipients'] ?? CampaignReport::query()->where('campaign_id', $campaignIdStr)->count());
        $userId = (int) $context['user_id'];
        $attemptsProcessed = 0;

        while (true) {
            $batch = CampaignReport::query()
                ->where('campaign_id', $campaignIdStr)
                ->where('status', 'pending')
                ->orderBy('id')
                ->limit(100)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $report) {
                $result = $sender->send((string) $report->mobile_number, $messagesApi, $waToken, $context);

                if ($result['success'] && $result['message_id']) {
                    try {
                        Redis::setex('whatsapp:msg:'.$result['message_id'], 172800, (string) $report->id);
                    } catch (\Throwable $e) {
                        Log::warning('Redis setex failed for campaign message', ['error' => $e->getMessage()]);
                    }

                    CampaignReport::query()->where('id', $report->id)->update([
                        'message_id' => $result['message_id'],
                        'status' => 'sent',
                        'updated_at' => now(),
                    ]);
                } else {
                    CampaignReport::query()->where('id', $report->id)->update([
                        'status' => 'failed',
                        'updated_at' => now(),
                    ]);
                }

                $attemptsProcessed++;
                if ($attemptsProcessed % self::PROGRESS_INTERVAL === 0) {
                    $this->broadcastProgress($userId, $campaignIdStr, $totalRecipients);
                }
            }
        }

        if ($attemptsProcessed > 0 && $attemptsProcessed % self::PROGRESS_INTERVAL !== 0) {
            $this->broadcastProgress($userId, $campaignIdStr, $totalRecipients);
        }

        $failedCount = CampaignReport::query()
            ->where('campaign_id', $campaignIdStr)
            ->where('status', 'failed')
            ->count();
        $totalSent = CampaignReport::query()
            ->where('campaign_id', $campaignIdStr)
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->count();

        event(new CampaignCompleted($userId, $this->campaignId, $totalSent, $failedCount));
        Cache::forget($this->cacheKey());
    }

    private function broadcastProgress(int $userId, string $campaignIdStr, int $totalRecipients): void
    {
        $sent = CampaignReport::query()
            ->where('campaign_id', $campaignIdStr)
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->count();
        $percent = $totalRecipients > 0 ? round(($sent / $totalRecipients) * 100, 2) : 0.0;

        event(new CampaignProgress($userId, $this->campaignId, $sent, $totalRecipients, $percent));
    }
}
