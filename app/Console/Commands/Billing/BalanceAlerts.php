<?php

namespace App\Console\Commands\Billing;

use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class BalanceAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'balance-alerts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check balances and pricing models and send alerts if necessary';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Fetch users with their associated balances and pricing models
        try {
            $users = User::where('whatsapp_alerts','true')->with(['balance', 'pricingModel'])->get();
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
            $headers = [
                'Authorization' => 'Bearer EAANGg6lYZBAIBO6PGbZAsltcLi0eXHs4zvQevKGBEgPZBM3R3jSZC7LmRAG2PVXdACeL9Tf9fehRUxaMZAWntO0vvDZBzuiKGZAAClT1tPdr6spitQdlPS2CRN0H56XzNb3JXPa8rd8XuuFdjCAldpOVxZBLoZAKyuChM2ywGR4KLKsqGtJs8VsZCN5ROrZCG2MHVgefvVNsh4PDTatG9KHFZCBG0rxYqzhQHSyiYfGkMxzZCK2l8GpGJD7oA',
                'Content-Type' => 'application/json'
            ];
            $client = new Client();
            $url = 'https://graph.facebook.com/v18.0/188069167733040/messages';




            foreach ($users as $user) {
                if ($user->latest_balance) {
                    $onlyUserData = User::where('id', $user->id)->first();
                    if ($user->latest_balance->total_credits < $user->pricing->price_alert) {

                        // $onlyUserData->credit_expired = 'true';
                        // $onlyUserData->save();

                        //\Log::info("User [{$onlyUserData->id}] credit_expired field updated successfully.");
                        // $response = (new Client())->post($url, [
                        //     'headers' => $headers,
                        //     'json' => [
                        //         'messaging_product' => 'whatsapp',
                        //         'to' => '91' . $user->phone_number,
                        //         'type' => 'text',
                        //         'text' => ['preview_url' => false, 'body' => 'Low Balance']
                        //     ]
                        // ]);
                        \Log::info('Balance Alerts command executed successfully.' . '91' . $user->phone_number);

                    } else {
                        // $onlyUserData->credit_expired = 'false';
                        //     $onlyUserData->save();
                        //     \Log::info("User [{$onlyUserData->id}] credit_expired field updated false.");
                    }

                }

            }
            $this->info('Balance alerts checked and alerts sent successfully.');
        } catch (\Exception $e) {
            \Log::error('Error executing Balance Alerts command: ' . $e->getMessage());
        }
    }
}
