<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Billing\Balance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BalanceController extends Controller
{
    public function index($id)
    {
        $cacheKeyLatestBalance = "user.{$id}.latest_balance";
        $cacheKeyHistory = "user.{$id}.credit_history";

        $latest_balance = Cache::remember($cacheKeyLatestBalance, now()->addMinutes(30), function () use ($id) {
            return Balance::where('user_id', $id)
                ->latest()
                ->value('total_credits');
        });

        $history = Cache::remember($cacheKeyHistory, now()->addMinutes(15), function () use ($id) {
            return Balance::where('user_id', $id)
                ->where('auto_deduction', null)
                ->select('new_credit', 'total_credits', 'auto_deduction', 'manual_deduction', 'remarks', 'created_at')
                ->latest()
                ->limit(50)
                ->get()
                ->reverse()
                ->values();
        });

        if (Auth::check() && Auth::user()->hasRole('Admin')) {
            $page = max(1, (int) request()->query('page', 1));
            $perPage = min(100, max(1, (int) request()->query('per_page', 50)));

            $paginated = $this->paginatedAdminCreditHistory($page, $perPage);

            return response()->json([
                'history' => $history,
                'allHistory' => $paginated['data'],
                'allHistory_meta' => $paginated['meta'],
                'latest_balance' => $latest_balance,
            ]);
        }

        return response()->json([
            'history' => $history,
            'latest_balance' => $latest_balance,
        ]);
    }

    /**
     * @return array{data: \Illuminate\Support\Collection, meta: array<string, int>}
     */
    private function paginatedAdminCreditHistory(int $page, int $perPage): array
    {
        $hot = DB::table('balances')
            ->select(
                'id',
                'user_id',
                'account_manager_id',
                'new_credit',
                'total_credits',
                'auto_deduction',
                'manual_deduction',
                'remarks',
                'created_at',
                DB::raw("'hot' as source")
            )
            ->whereNull('auto_deduction');

        $unionQuery = $hot;

        if (Schema::hasTable('balances_archive')) {
            $archive = DB::table('balances_archive')
                ->select(
                    'original_id as id',
                    'user_id',
                    'account_manager_id',
                    'new_credit',
                    'total_credits',
                    'auto_deduction',
                    'manual_deduction',
                    'remarks',
                    'created_at',
                    DB::raw("'archive' as source")
                )
                ->whereNull('auto_deduction');

            $unionQuery = $hot->unionAll($archive);
        }

        $sub = DB::query()->fromSub($unionQuery, 'credit_history');

        $total = (clone $sub)->count();
        $rows = (clone $sub)
            ->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $userIds = $rows->pluck('user_id')->merge($rows->pluck('account_manager_id'))->filter()->unique();
        $users = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'company_name'])
            ->keyBy('id');

        $data = $rows->map(function ($row) use ($users) {
            $user = $users->get($row->user_id);
            $manager = $row->account_manager_id ? $users->get($row->account_manager_id) : null;

            return [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'new_credit' => $row->new_credit,
                'total_credits' => $row->total_credits,
                'auto_deduction' => $row->auto_deduction,
                'manual_deduction' => $row->manual_deduction,
                'remarks' => $row->remarks,
                'created_at' => $row->created_at,
                'source' => $row->source,
                'user' => $user ? ['id' => $user->id, 'company_name' => $user->company_name] : null,
                'account_manager' => $manager ? ['id' => $manager->id, 'company_name' => $manager->company_name] : null,
            ];
        })->values();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }
  
    public function create(Request $request)
    {
        try {
            // Begin transaction for potential multiple operations
            $user = User::find($request->user_id);
            if (!$user) {
                throw new \Exception('User not found');
            }

            $reporting_user_id = $user->reporting_user;

            // Create the balance entry for the user
            $balance = new Balance();
            $balance->user_id = $request->user_id;
            $balance->new_credit = $request->newCredit;
            $balance->total_credits = $request->amount;
            $balance->payment_type = 'cash';
            $balance->account_manager_id = $reporting_user_id;

            if ($request->deduction) {
                $balance->manual_deduction = 'true';
 			} else {
                // Only proceed with deduction logic if not manually marking as deduction
                $logged_in_user = Auth::user();
                if ($logged_in_user->hasRole('Admin') || $logged_in_user->hasRole('Reseller')) {
					
                    // Check if there's a reporting user (account manager)
                    if ($reporting_user_id) {
                      	
                        $account_manager = User::find($reporting_user_id);
                        
                        if ($account_manager && $account_manager->hasRole('Reseller')) {
                            // Check billing settings for the user
                           
                            if ($user->user_billing == 'acount-manager') {
                              	
                                // Check if account manager's billing is set to 'admin'
                                if ($account_manager->user_billing == 'admin') {
                                    // Deduct balance from account manager
                                    $manager_current_balance = Balance::where('user_id', $reporting_user_id)
                                        ->latest()
                                        ->value('total_credits') ?? 0;

                                    $new_manager_balance = $manager_current_balance - $request->newCredit;

                                    // Create deduction entry for account manager
                                    $manager_balance = new Balance();
                                    $manager_balance->user_id = $reporting_user_id;
                                    $manager_balance->new_credit = $request->newCredit; // Negative value for deduction
                                    $manager_balance->total_credits = $new_manager_balance;
                                  	$manager_balance->manual_deduction = 'true';
                                    $manager_balance->payment_type = 'transfer';
                                    $manager_balance->remarks = 'Auto deduction for user #' . $user->company_name;
                                    $manager_balance->save();

                                    // Clear account manager's cache
                                    Cache::forget("user.{$reporting_user_id}.latest_balance");
                                    Cache::forget("user.{$reporting_user_id}.credit_history");
                                }
                                // If account manager billing is 'self', no action needed
                            }
                            // If user billing is 'self', no action needed
                        }
                    }
                }
            }

            $balance->save();

            // Clear specific cache keys
            Cache::forget("user.{$request->user_id}.latest_balance");
            Cache::forget("user.{$request->user_id}.credit_history");
            Cache::forget("admin.all_credit_history");
            return response()->json(['status' => 'true', 'message' => 'Credit Updated Successfully'], 201);
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Error updating credit: ' . $e->getMessage());

            // Return an error response
            return response()->json(['status' => 'false', 'message' => $e->getMessage()], 500);
        }
    }
}
