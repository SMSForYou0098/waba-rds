<?php

namespace App\Console\Commands\Chat;

use App\Models\Chat\IdleMessageUser;
use App\Models\Report\OutReport;
use App\Models\Report\Report;
use App\Models\User;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class SendIdleTimeoutMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-idle-timeout-messages';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send idle timeout messages to users who have not responded for more than 5 minutes';

    public function handle()
    {
        $this->info('Checking for idle reports...');

        $users = User::whereHas('idleTimerData', function ($query) {
            $query->where('status', 1);
        })->with('idleTimerData', 'reports')->get();

        if ($users->isEmpty()) {
            $this->info('No users with idle timer data found.');
            return 0;
        }

        foreach ($users as $user) {
            $idleMessageData = $user->idleTimerData;
            if (!$idleMessageData || !$idleMessageData->created_at) {
                $this->warn("No valid idle timer data found for user '{$user->name}'");
                continue;
            }

            $message = $idleMessageData->message;
            $minutes = $idleMessageData->minutes;

            if (!is_numeric($minutes) || $minutes <= 0) {
                $this->warn("Invalid idle timeout minutes for user '{$user->name}'");
                continue;
            }

            $reports = Report::whereIn('id', function ($query) use ($idleMessageData, $minutes, $user) {
                $query->selectRaw('MAX(id)')
                    ->from('reports')
                    ->where('display_phone_number', $user->whatsapp_number)
                    ->where('created_at', '>=', $idleMessageData->updated_at)
                    ->groupBy('wa_id'); // Get the latest report ID for each unique `wa_id`
            })
                ->latest()
                ->get();
            if ($reports->isEmpty()) {
                $this->info("No idle reports found for user '{$user->name}'");
                continue;
            }

            foreach ($reports as $report) {
                // Get the difference between current time and report's created_at
                $reportCreatedAt = Carbon::parse($report->created_at);
                $currentTime = Carbon::now()->setTimezone('Asia/Kolkata');
                $timeDifferenceInMinutes = $currentTime->diffInMinutes($reportCreatedAt);

                // Check if the difference equals the idle timeout minutes
                if ($timeDifferenceInMinutes >= $minutes) {
                    // Double-check to prevent duplicate messages
                    if (
                        IdleMessageUser::where('user_id', $user->id)
                            ->where('number', $report->wa_id)
                            ->exists()
                    ) {
                        $this->info("Idle message already sent to user '{$report->profile_name}' (Phone: {$report->wa_id}) for report ID: {$report->id}");
                        continue;
                    }

                    $this->sendTimeoutMessage($user, $report, $message);
                }
            }
        }

        $this->info('Idle timeout messages sent successfully.');
        return 0;
    }

    protected function sendTimeoutMessage($user, $report, $message)
    {
        $this->info("Sending timeout message to user {$report->wa_id} for report ID: {$report->id}");
        $url = "https://graph.facebook.com/v20.0/{$user->userConfig->whatsapp_phone_id}/messages";
        $headers = [
            'Authorization' => "Bearer {$user->userConfig->meta_access_token}",
            'Content-Type' => 'application/json'
        ];
        $this->sendTextMessage($report->wa_id, $message, $url, $headers, $report, $user->id);
    }

    private function sendTextMessage($waId, $customText, $url, $headers, $report, $user_id)
    {
        $response = (new Client())->post($url, [
            'headers' => $headers,
            'json' => [
                'messaging_product' => 'whatsapp',
                'to' => $waId,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $customText]
            ]
        ]);
        $responseBody = $response->getBody()->getContents();
        $response = json_decode($responseBody);
        if ($response) {
            $this->storeIdleMessageData($user_id, $waId);
            $data = $this->MakeOutReport($response, $waId, $report, $user_id);
            return $data;
        }
        return $response;
    }

    private function storeIdleMessageData($user_id, $waId)
    {
        $idleMessageUser = new IdleMessageUser();
        $idleMessageUser->user_id = $user_id;
        $idleMessageUser->number = $waId;
        $idleMessageUser->save();
    }
    protected function MakeOutReport($response, $waId, $report, $user_id)
    {
        $out_report = new OutReport();
        $out_report->user_id = $user_id;
        $out_report->display_phone_number = $report->display_phone_number;
        $out_report->phone_number_id = $report->phone_number_id;
        $out_report->status = 'sent';
        $out_report->status_id = $response->messages[0]->id;
        $out_report->recipient_id = $waId;
        $out_report->created_at = now();
        $out_report->save();
        $this->apiToken = NULL;

        return response()->json(['response' => $out_report], 200);
    }
}
