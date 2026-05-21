<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Campaign\Campaign;
use App\Models\Report\OutReport;
use App\Models\User;
use App\Services\Meta\MetaApiUrl;
use App\Services\Meta\MetaGraphClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function __construct(
        private readonly MetaGraphClient $graph,
    ) {}

    public function weeklyReport($id)
    {
        $cacheKey = "weekly_report_v2_user_{$id}";

        try {
            $payload = Cache::remember($cacheKey, 3600, function () use ($id) {
                return $this->buildWeeklyReportPayload($id);
            });

            return response()->json($payload);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    private function buildWeeklyReportPayload(int $id): array
    {
        $user = User::where('id', $id)->with('userConfig')->firstOrFail();

        if (!$user->userConfig || !$user->userConfig->whatsapp_business_account_id || !$user->userConfig->meta_access_token) {
            throw new \RuntimeException('Missing configuration');
        }

        $wapId = $user->userConfig->whatsapp_business_account_id;
        $waToken = $user->userConfig->meta_access_token;
        $endUnix = time();
        $startUnix = Carbon::now()->subDays(7)->startOfDay()->timestamp;

        $apiUrl = MetaApiUrl::analytics($wapId, $startUnix, $endUnix, $waToken);
        $result = $this->graph->get($apiUrl, $waToken);
        $responseData = $result['body'];
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
            ->map(fn ($i) => Carbon::now()->subDays($i)->format('d/m/Y'))
            ->map(fn ($date) => [
                'date' => $date,
                'marketingCount' => $marketingData[$date] ?? 0,
                'utilityCount' => $utilityData[$date] ?? 0,
                'serviceCount' => $serviceData[$date] ?? 0,
                'authCount' => $authData[$date] ?? 0,
            ])
            ->toArray();

        return [
            'marketingData' => array_column($last7DaysData, 'marketingCount'),
            'utilityData' => array_column($last7DaysData, 'utilityCount'),
            'serviceData' => array_column($last7DaysData, 'serviceCount'),
            'authData' => array_column($last7DaysData, 'authCount'),
            'last7DaysData' => $last7DaysData,
        ];
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
