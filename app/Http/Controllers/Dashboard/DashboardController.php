<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Campaign\Campaign;
use App\Models\Report\OutReport;
use App\Models\Report\Report;
use App\Models\Campaign\ScheduleCampaign;
use App\Models\User;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function weeklyReport($id)
    {
        // Cache key unique to this user and function
        $cacheKey = "weekly_report_user_{$id}";
        // Cache duration - 1 hour (3600 seconds)
        $cacheDuration = 3600;

        // Try to get data from cache first
        return Cache::remember($cacheKey, $cacheDuration, function () use ($id) {
            try {
                $user = User::where('id', $id)->with('userConfig')->firstOrFail();
				
                if (!$user->userConfig || !$user->userConfig->whatsapp_business_account_id || !$user->userConfig->meta_access_token) {
                    return response()->json(['error' => 'Missing configuration'], 200);
                }

                $wapId = $user->userConfig->whatsapp_business_account_id;
                $waToken = $user->userConfig->meta_access_token;

                $apiUrl = str_replace(
                    [':wapId:', ':realtime_unix:', ':waToken:'],
                    [$wapId, time(), $waToken],
                    env('WA_API_ANALYTICS')
                );

                $client = new Client();
                
                $response = $client->get($apiUrl, [
                    'headers' => ['Authorization' => 'Bearer ' . $waToken]
                ]);
				
                $responseData = json_decode($response->getBody()->getContents(), true);
                $dataPoints = $responseData['conversation_analytics']['data'][0]['data_points'] ?? [];
                $marketingData = $utilityData = $serviceData = $authData = [];

                foreach ($dataPoints as $dataPoint) {
                    $date = Carbon::createFromTimestamp($dataPoint['start'])->format('d/m/Y');

                    switch ($dataPoint['conversation_category']) {
                        case 'MARKETING':
                            $marketingData[$date] = ($marketingData[$date] ?? 0) + $dataPoint['conversation'];
                            break;
                        case 'UTILITY':
                            $utilityData[$date] = ($utilityData[$date] ?? 0) + $dataPoint['conversation'];
                            break;
                        case 'SERVICE':
                            $serviceData[$date] = ($serviceData[$date] ?? 0) + $dataPoint['conversation'];
                            break;
                        case 'AUTHENTICATION':
                            $authData[$date] = ($authData[$date] ?? 0) + $dataPoint['conversation'];
                            break;
                    }
                }

                $last7DaysData = collect(range(6, 0))
                    ->map(fn($i) => Carbon::now()->subDays($i)->format('d/m/Y'))
                    ->map(fn($date) => [
                        'date' => $date,
                        'marketingCount' => $marketingData[$date] ?? 0,
                        'utilityCount' => $utilityData[$date] ?? 0,
                        'serviceCount' => $serviceData[$date] ?? 0,
                        'authCount' => $authData[$date] ?? 0
                    ])
                    ->toArray();

                return response()->json([
                    'marketingData' => array_column($last7DaysData, 'marketingCount'),
                    'utilityData' => array_column($last7DaysData, 'utilityCount'),
                    'serviceData' => array_column($last7DaysData, 'serviceCount'),
                    'authData' => array_column($last7DaysData, 'authCount'),
                    'last7DaysData' => $last7DaysData
                ]);
            } catch (\Exception $e) {
                return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
            }
        });
    }


    public function DigitCounts($id)
    {
        $user = User::findOrFail($id);

        $user_phone_number = $user->whatsapp_number;
        $today = Carbon::today()->toDateString();
        $campaignCount = Campaign::whereDate('created_at', $today)->where('user_id', $id)->count();
        $delievery_reports = Cache::remember('delievery_reports', 60, function () use ($today, $user_phone_number) {
            return OutReport::whereDate('created_at', $today)->where('display_phone_number', $user_phone_number)->get();
        });
        // Retrieve only today's data
        $outReports = OutReport::whereDate('created_at', $today)->where('display_phone_number', $user_phone_number)->get();
        $Campaign = Campaign::whereDate('created_at', $today)->where('user_id', $id)->with('campaignReports')->withCount('campaignReports')->get();
        //$scheduledCampaign = ScheduleCampaign::with('campaignReports')->withCount('campaignReports')->get();
        return response()->json([
            'campaignCount' => $campaignCount,
            'deliveryRatio' => $delievery_reports,
            'outReports' => $outReports,
            'Campaign' => $Campaign,
            //'scheduledCampaign' => $scheduledCampaign,
        ], 200);
    }

}
