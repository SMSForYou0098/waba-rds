<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Billing\Balance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Cache;
use DB;
class BalanceController extends Controller
{

    public function index($id)
    {
        // Cache key for the latest balance and history
        $cacheKeyLatestBalance = "user.{$id}.latest_balance";
        $cacheKeyHistory = "user.{$id}.credit_history";
        $cacheKeyAllHistory = "admin.all_credit_history";

        // Try to get the latest balance from cache
        $latest_balance = Cache::remember($cacheKeyLatestBalance, now()->addMinutes(30), function () use ($id) {
            return Balance::where('user_id', $id)
                ->latest()
                ->value('total_credits');
        });

        // Get credit history (limited to 50 records)
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

        // If user is an admin, include all history
        if (Auth::check() && Auth::user()->hasRole('Admin')) {
            $allHistory = Cache::remember($cacheKeyAllHistory, now()->addMinutes(10), function () {
                return Balance::where('auto_deduction', null)
                    ->with([
                        'user:id,company_name',
                        'accountManager:id,company_name'
                    ])
                    ->get()
                    ->values();
            });

            return response()->json([
                'history' => $history,
                'allHistory' => $allHistory,
                'latest_balance' => $latest_balance
            ]);
        }

        return response()->json([
            'history' => $history,
            'latest_balance' => $latest_balance
        ]);
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
