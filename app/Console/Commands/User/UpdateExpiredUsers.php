<?php

namespace App\Console\Commands\User;

use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdateExpiredUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update-expireds-users';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Fetch users with their associated balances and pricing models
        try {
            $users = User::with(['balance', 'pricingModel'])->get();
           // \Log::info($users);
            $users->each(function ($user) {
                if ($user->balance) {
                    $user->latest_balance = $user->balance()->latest()->first();
                }
                if ($user->pricingModel) {
                    $user->pricing = $user->pricingModel()->latest()->first();
                }
                unset ($user->balance);
                unset ($user->pricingModel);
            });
            foreach ($users as $user) {
                if ($user->latest_balance) {
                    $onlyUserData = User::where('id', $user->id)->first();
                    if ($user->latest_balance->total_credits < $user->pricing->marketing_price) {
                        $onlyUserData->credit_expired = 'true';
                        $onlyUserData->save();
                       // \Log::info("User [{$onlyUserData->name}] credit_expired field updated true.");

                    } else {
                        $onlyUserData->credit_expired = null;
                        $onlyUserData->save();
                       // \Log::info("User [{$onlyUserData->name}] credit_expired field updated false.");
                    }

                }

            }
            $this->info('Balance alerts checked and alerts sent successfully.');
        } catch (\Exception $e) {
            \Log::error('Error executing Balance Alerts command: ' . $e->getMessage());
        }
    }
}
