<?php

namespace App\Console\Commands\Cache;

use Illuminate\Console\Command;
use App\Models\Campaign\ScheduleCampaign;
use Cache;

class StoreScheduleCampaignInCache extends Command
{
    protected $signature = 'getScheduleData';
    protected $description = 'Refresh cache with today\'s data';

    public function handle()
    {
        $currentDate = now()->setTimezone('Asia/Kolkata')->toDateString();
        // return $currentDate;
        $data = ScheduleCampaign::where('status','pending')->whereDate('schedule_date', $currentDate)
        ->with([
            'user.apiKey' => function ($query) {
                $query->where('status', 'true');
            }
        ])->get();
       // \Log::info($data);
        Cache::put('todays_data', $data);
        $this->info('Cache refreshed successfully.');
    }
}
