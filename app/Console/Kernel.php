<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Carbon\Carbon;
use DB;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('getScheduleData')->dailyAt('00:00');
        $schedule->command('update-expireds-users')->everyMinute();
        $schedule->command('campaigns:process')->everyMinute();
        $schedule->command('balance-alerts')->dailyAt('10:00');
      	$schedule->command('files:delete-old-media')->everyMinute();
		$schedule->command('app:send-idle-timeout-messages')->everyMinute();
        $schedule->command('logs:clear')->daily();
        //$schedule->command('pending-to-failed')->hourly();
        $schedule->command('media:reupload')->dailyAt('00:00');
      	$schedule->command('webhook:process')->everyFiveMinutes();
      	//$schedule->command('plans:expire')->everyFiveMinutes();
      	//$schedule->command('campaigns:process-status')->everyFourHours();
      	//$schedule->command('campaigns:process-status')->everyFiveMinutes();
        $schedule->call(function () {
            $threshold = Carbon::now()->subMinutes(5);

            DB::table('chatbot_states')
                ->where('updated_at', '<', $threshold)
                ->delete();

            DB::table('chatbot_memories')
                ->where('updated_at', '<', $threshold)
                ->delete();
        })->everyMinute();
    }
  

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
