<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CampaignFeederJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue('campaign-feeder');
    }

    public function handle(): void
    {
        $lockKey = 'campaign-feeder:lock';
        if (! Cache::add($lockKey, 1, now()->addSeconds(20))) {
            return;
        }

        try {
            $maxQueueDepth = (int) config('services.meta.feeder_max_queue_depth', 300);
            $currentDepth = DB::table('jobs')->where('queue', 'campaign-meta')->count();
            if ($currentDepth >= $maxQueueDepth) {
                $this->requeueSelf();

                return;
            }

            $perCampaignSlice = max(1, (int) config('services.meta.feeder_per_campaign_slice', 15));
            $maxDispatchPerTick = max(1, (int) config('services.meta.feeder_max_dispatch_per_tick', 120));
            $remainingBudget = $maxDispatchPerTick;

            $activeCampaigns = DB::table('campaign_reports')
                ->select('campaign_id', DB::raw('MIN(id) as min_id'))
                ->where('status', 'pending')
                ->whereNotNull('whatsapp_phone_id')
                ->whereNotNull('payload')
                ->groupBy('campaign_id')
                ->orderBy('min_id')
                ->limit(200)
                ->get();

            if ($activeCampaigns->isEmpty()) {
                return;
            }

            foreach ($activeCampaigns as $campaignRow) {
                if ($remainingBudget <= 0) {
                    break;
                }

                $slice = min($perCampaignSlice, $remainingBudget);
                $reports = DB::table('campaign_reports')
                    ->select('id', 'whatsapp_phone_id')
                    ->where('campaign_id', $campaignRow->campaign_id)
                    ->where('status', 'pending')
                    ->whereNotNull('whatsapp_phone_id')
                    ->orderBy('id')
                    ->limit($slice)
                    ->get();

                foreach ($reports as $report) {
                    SendCampaignMessageJob::dispatch((int) $report->id, (string) $report->whatsapp_phone_id);
                    $remainingBudget--;
                    if ($remainingBudget <= 0) {
                        break;
                    }
                }
            }

            $stillPending = DB::table('campaign_reports')
                ->where('status', 'pending')
                ->whereNotNull('whatsapp_phone_id')
                ->whereNotNull('payload')
                ->exists();

            if ($stillPending) {
                $this->requeueSelf();
            }
        } finally {
            Cache::forget($lockKey);
        }
    }

    private function requeueSelf(): void
    {
        $tickSeconds = max(1, (int) config('services.meta.feeder_tick_seconds', 1));
        self::dispatch()->delay(now()->addSeconds($tickSeconds));
    }
}
