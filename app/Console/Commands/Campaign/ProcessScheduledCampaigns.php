<?php

namespace App\Console\Commands\Campaign;

use App\Models\Campaign\ScheduleCampaign;
use App\Models\Campaign\ScheduleCampaignReport;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Carbon\Carbon;
use Cache;

class ProcessScheduledCampaigns extends Command
{

    protected $signature = 'campaigns:process';
    protected $description = 'Process scheduled campaigns';

    public function handle()
    {
        $currentTime = now();
        $scheduledCampaigns = Cache::get('todays_data');
      	
        $client = new Client();


        if ($scheduledCampaigns) {
            // Fetch API keys for all campaigns at once
		 
            foreach ($scheduledCampaigns as $campaign) {
				
                $scheduledDatetime = $campaign->schedule_date . ' ' . $campaign->schedule_time;
                $scheduledTime = Carbon::createFromFormat('Y-m-d H:i:s', $scheduledDatetime);
             
                // Extract minutes from current time
                // Extract hours and minutes from current time
                $currentHour = $currentTime->format('H');
                $currentMinute = $currentTime->format('i');
                // $currentSecond = $currentTime->format('s');
                 
                // Extract hours and minutes from scheduled time
                $scheduledHour = $scheduledTime->format('H');
                $scheduledMinute = $scheduledTime->format('i');
                //$scheduledSecond = $scheduledTime->format('s');
                // if ($scheduledTime == $currentTime) {
              	 //$status = $currentMinute === $scheduledMinute ? 'true' : 'false';
              	 //$this->info($status);
              	// $this->info($scheduledMinute);
                if ($currentMinute == $scheduledMinute && $currentHour == $scheduledHour) {
                    $to = explode(',', $campaign->numbers);
                    $reqType = $campaign->campaign_type;
                    $templateName = $campaign->template_name;
                    $mediaLink = $campaign->header_media_url;
                    $message = $campaign->custom_text;
                    $values = $campaign->body_values;
                    $buttonValue = $campaign->button_value;
                    $apiKeyData = $campaign->user->ApiKey->filter(function ($key) {
                        return $key->status === 'true';
                    });
                    //$this->info($apiKeyData);
                    $apiKey = $apiKeyData->first()->key;
                    $Count = 0;
                    foreach ($to as $number) {
                        $Count++;
                        // Constructing the URL with encoded query parameters
                        $queryParams = [
                            'to' => $number,
                            'type' => $reqType === 'template' ? 'T' : 'C',
                        ];

                        if ($reqType === 'template') {
                            $queryParams['tname'] = $templateName;
                        } else {
                            $queryParams['message'] = $message;
                        }

                        $queryParams['media_id'] = $mediaLink;
                        $queryParams['values'] = $values;
                        $queryParams['button_value'] = $buttonValue;
                        $url = 'https://waba.smsforyou.biz/api/send-messages?apikey=' . $apiKey . '&' . http_build_query($queryParams);
						

                        // Making HTTP POST request inside the loop
                        $response = $client->post($url);
                        $responseBody = $response->getBody()->getContents();
                        $result = json_decode($responseBody);
                        if ($result->status == true) {
                            $campaignReport = new ScheduleCampaignReport();
                            $campaignReport->campaign_id = $campaign->id;
                            $campaignReport->message_id = decrypt($result->message_id);
                            $campaignReport->mobile_number = $number;
                            $campaignReport->status = 'sent';
                            $campaignReport->save();
                        }
                        if (($Count == count($to))) {
                            $campaign = ScheduleCampaign::findOrFail($campaign->id);
                            $campaign->status = 'Completed';
                            $campaign->save();


                            //sent mail alert
                            $url = 'https://waba.smsforyou.biz/api/send-email/' . $campaign->user_id;
                            $template = 'template';  // Dynamic key as a string
                            $templateValue = 'Schedule Campaign Execute';  // Value for the template
                            $email = 'email';  // Dynamic key as a string
                            $emailValue = $campaign->user->email;  // Value for the email

                            $campaign_name = 'campaign_name'; // Value for the
                            $campaign_value = $campaign->name; // Value for the
                            $date = 'date'; // Value for the

                            $response = $client->post($url, [
                                'form_params' => [
                                    $template => $templateValue,
                                    $email => $emailValue,
                                    $campaign_name => $campaign_value,
                                    $date => $scheduledDatetime
                                ]
                            ]);
                            $responseBody = $response->getBody()->getContents();
                            $this->info($responseBody);
                        }
                    }
                }
            }
        } else {
            $this->info('No scheduled campaigns for today.');
        }

    }

}
