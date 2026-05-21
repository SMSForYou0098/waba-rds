<?php

namespace App\Services\User;

use App\Models\Report\Logdata;
use App\Services\Billing\CampaignPricingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserReportingService
{
    public function __construct(
        private readonly CampaignPricingService $campaignPricingService,
    ) {}

    public function lowBalanceUsers()
    {
        return DB::table('users as u')
            ->select([
                'u.id',
                'u.name',
                'u.email',
                'u.whatsapp_number',
                'u.phone_number',
                'u.email_alerts',
                'u.whatsapp_alerts',
                'u.text_alerts',
                'b.total_credits as latest_balance',
                'p.price_alert',
                'a.key as api_key',
            ])
            ->join(DB::raw('(SELECT user_id, MAX(id) as max_id FROM balances GROUP BY user_id) as latest_b'), function ($join) {
                $join->on('u.id', '=', 'latest_b.user_id');
            })
            ->join('balances as b', function ($join) {
                $join->on('latest_b.max_id', '=', 'b.id');
            })
            ->leftJoin(DB::raw('(SELECT user_id, MAX(id) as max_id FROM pricing_models GROUP BY user_id) as latest_p'), function ($join) {
                $join->on('u.id', '=', 'latest_p.user_id');
            })
            ->leftJoin('pricing_models as p', function ($join) {
                $join->on('latest_p.max_id', '=', 'p.id');
            })
            ->leftJoin(DB::raw('(SELECT user_id, MAX(id) as max_id FROM api_keys GROUP BY user_id) as latest_a'), function ($join) {
                $join->on('u.id', '=', 'latest_a.user_id');
            })
            ->leftJoin('api_keys as a', function ($join) {
                $join->on('latest_a.max_id', '=', 'a.id');
            })
            ->whereRaw('b.total_credits < p.price_alert')
            ->get();
    }

    public function todayLogs()
    {
        return Logdata::whereDate('created_at', Carbon::today())->get();
    }

    public function clearLogs(): void
    {
        Logdata::truncate();
    }

    /**
     * @return array{status: string, message: string, users?: list<array<string, mixed>>, summary?: array<string, mixed>, http_status: int}
     */
    public function balanceAndCampaignData(): array
    {
        try {
            $currentMonth = Carbon::now()->startOfMonth();

            $data = DB::table('users as u')
                ->select([
                    'u.id',
                    'u.company_name',
                    DB::raw('(SELECT total_credits FROM balances
                         WHERE user_id = u.id
                         AND DATE(created_at) >= "'.$currentMonth->toDateString().'"
                         ORDER BY created_at ASC
                         LIMIT 1) as month_start_balance'),
                    DB::raw('(SELECT total_credits FROM balances
                         WHERE user_id = u.id
                         ORDER BY created_at DESC
                         LIMIT 1) as current_balance'),
                    DB::raw('(SELECT COUNT(*) FROM campaigns
                         WHERE user_id = u.id
                         AND created_at >= "'.$currentMonth->toDateTimeString().'") as total_campaigns_this_month'),
                ])
                ->whereNull('u.deleted_at')
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->whereRaw('model_has_roles.model_id = u.id')
                        ->whereNotIn('roles.name', ['Support Agent', 'Admin']);
                })
                ->orderBy('u.company_name')
                ->get();

            $users = [];
            $totalSummary = [
                'total_users' => 0,
                'total_campaigns_all_users' => 0,
                'total_campaign_cost_all_users' => 0,
                'total_current_balance_all_users' => 0,
            ];

            foreach ($data as $user) {
                $campaignDetails = $this->getUserCampaignDetails($user->id, $currentMonth);

                $userData = [
                    'user_id' => $user->id,
                    'company_name' => $user->company_name,
                    'total_campaigns_this_month' => (int) $user->total_campaigns_this_month,
                    'total_campaign_cost_this_month' => (float) $campaignDetails['total_cost'],
                    'month_start_balance' => (float) ($user->month_start_balance ?? 0),
                    'current_balance' => (float) ($user->current_balance ?? 0),
                    'balance_difference' => (float) (($user->current_balance ?? 0) - ($user->month_start_balance ?? 0)),
                    'campaigns' => $campaignDetails['campaigns'],
                ];

                $users[] = $userData;
                $totalSummary['total_users']++;
                $totalSummary['total_campaigns_all_users'] += $userData['total_campaigns_this_month'];
                $totalSummary['total_campaign_cost_all_users'] += $userData['total_campaign_cost_this_month'];
                $totalSummary['total_current_balance_all_users'] += $userData['current_balance'];
            }

            return [
                'status' => 'success',
                'message' => 'Users balance and campaign data retrieved successfully',
                'users' => $users,
                'summary' => $totalSummary,
                'http_status' => 200,
            ];
        } catch (\Exception $e) {
            Log::error('Error retrieving users balance and campaign data: '.$e->getMessage());

            return [
                'status' => 'error',
                'message' => 'Failed to retrieve users balance and campaign data: '.$e->getMessage(),
                'http_status' => 500,
            ];
        }
    }

    /**
     * @return array{campaigns: list<array<string, mixed>>, total_cost: float}
     */
    private function getUserCampaignDetails(int|string $userId, Carbon $currentMonth): array
    {
        try {
            $userPricing = DB::table('pricing_models')
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->first();

            if (! $userPricing) {
                return ['campaigns' => [], 'total_cost' => 0.0];
            }

            $campaigns = DB::table('campaigns')
                ->select('id', 'name', 'created_at')
                ->where('user_id', $userId)
                ->where('created_at', '>=', $currentMonth->toDateTimeString())
                ->orderByDesc('created_at')
                ->get();

            $campaignDetails = [];
            $totalCost = 0.0;

            foreach ($campaigns as $campaign) {
                $campaignReports = DB::table('campaign_reports')
                    ->select('template_category', DB::raw('COUNT(*) as count'))
                    ->where('campaign_id', $campaign->id)
                    ->groupBy('template_category')
                    ->get();

                $campaignCost = 0.0;
                $totalReports = 0;
                $categoryBreakdown = [];

                foreach ($campaignReports as $report) {
                    $price = $this->campaignPricingService->unitPriceForTemplateCategory($userPricing, $report->template_category);
                    $cost = $report->count * $price;
                    $campaignCost += $cost;
                    $totalReports += $report->count;

                    $categoryBreakdown[] = [
                        'template_category' => $report->template_category,
                        'count' => (int) $report->count,
                        'price_per_message' => (float) $price,
                        'subtotal' => (float) $cost,
                    ];
                }

                $totalCost += $campaignCost;

                $campaignDetails[] = [
                    'campaign_id' => $campaign->id,
                    'name' => $campaign->name,
                    'total_reports_count' => $totalReports,
                    'total_campaign_cost' => (float) $campaignCost,
                    'category_breakdown' => $categoryBreakdown,
                    'created_at' => $campaign->created_at,
                ];
            }

            return ['campaigns' => $campaignDetails, 'total_cost' => $totalCost];
        } catch (\Exception $e) {
            Log::error("Error getting campaign details for user {$userId}: ".$e->getMessage());

            return ['campaigns' => [], 'total_cost' => 0.0];
        }
    }
}
