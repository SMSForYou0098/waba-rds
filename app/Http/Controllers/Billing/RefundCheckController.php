<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefundCheckController extends Controller
{
    public function checkRefund($userId)
    {
        try {
            // Get duplicate report_ids with their details
            $duplicates = DB::table('balances')
                ->select(
                    'report_id',
                    DB::raw('COUNT(*) as duplicate_count'),
                    DB::raw('SUM(new_credit) as total_deducted'),
                    DB::raw("string_agg(id::text, ',' ORDER BY id) as record_ids"),
                    DB::raw("string_agg(new_credit::text, ',' ORDER BY id) as individual_amounts"),
                    DB::raw('MIN(created_at) as first_deduction'),
                    DB::raw('MAX(created_at) as last_deduction')
                )
                ->where('user_id', $userId)
                ->whereNotNull('report_id')
                ->groupBy('report_id')
                ->having('duplicate_count', '>', 1)
                ->orderBy('duplicate_count', 'desc')
                ->get();

            // Calculate refund amounts (keep first, refund rest)
            $refundSummary = [
                'user_id' => $userId,
                'total_duplicate_reports' => $duplicates->count(),
                'total_refund_amount' => 0,
                'total_excess_records' => 0,
                'duplicates' => []
            ];

            foreach ($duplicates as $duplicate) {
                $amounts = explode(',', $duplicate->individual_amounts);
                $recordIds = explode(',', $duplicate->record_ids);
                
                // Keep first amount, refund the rest
                $keepAmount = (float)$amounts[0];
                $refundAmounts = array_slice($amounts, 1);
                $refundIds = array_slice($recordIds, 1);
                
                $refundForThisReport = array_sum($refundAmounts);
                
                $refundSummary['total_refund_amount'] += $refundForThisReport;
                $refundSummary['total_excess_records'] += count($refundAmounts);
                
                $refundSummary['duplicates'][] = [
                    'report_id' => $duplicate->report_id,
                    'total_occurrences' => $duplicate->duplicate_count,
                    'excess_records' => $duplicate->duplicate_count - 1,
                    'keep_record_id' => $recordIds[0],
                    'keep_amount' => $keepAmount,
                    'refund_record_ids' => $refundIds,
                    'refund_amounts' => $refundAmounts,
                    'refund_total' => $refundForThisReport,
                    'first_deduction' => $duplicate->first_deduction,
                    'last_deduction' => $duplicate->last_deduction
                ];
            }

            // Get user's current balance
            $currentBalance = DB::table('balances')
                ->where('user_id', $userId)
                ->orderBy('id', 'desc')
                ->value('total_credits') ?? 0;

            $refundSummary['current_balance'] = (float)$currentBalance;
            $refundSummary['balance_after_refund'] = $currentBalance + $refundSummary['total_refund_amount'];

            return response()->json([
                'success' => true,
                'refund_summary' => $refundSummary
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function processRefund($userId)
    {
        try {
            DB::beginTransaction();

            // Get duplicate records to delete (keep first, delete rest)
            $duplicateIds = DB::table('balances as b1')
                ->join('balances as b2', function($join) {
                    $join->on('b1.report_id', '=', 'b2.report_id')
                         ->on('b1.user_id', '=', 'b2.user_id')
                         ->on('b1.id', '>', 'b2.id');
                })
                ->where('b1.user_id', $userId)
                ->whereNotNull('b1.report_id')
                ->pluck('b1.id')
                ->toArray();

            if (empty($duplicateIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No duplicate records found for refund'
                ]);
            }

            // Calculate refund amount
            $refundAmount = DB::table('balances')
                ->whereIn('id', $duplicateIds)
                ->sum('new_credit');

            // Get current balance
            $currentBalance = DB::table('balances')
                ->where('user_id', $userId)
                ->orderBy('id', 'desc')
                ->value('total_credits') ?? 0;

            // Delete duplicate records
            $deletedCount = DB::table('balances')
                ->whereIn('id', $duplicateIds)
                ->delete();

            // Add refund record
            $newBalance = $currentBalance + $refundAmount;
            
            DB::table('balances')->insert([
                'user_id' => $userId,
                'new_credit' => 0,
                'alert_credit' => $refundAmount,
                'total_credits' => $newBalance,
                'payment_type' => 'refund',
                'remarks' => 'Refund for duplicate report_id deductions',
                'manual_deduction' => null,
                'auto_deduction' => 'false',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Refund processed successfully',
                'deleted_records' => $deletedCount,
                'refund_amount' => $refundAmount,
                'previous_balance' => $currentBalance,
                'new_balance' => $newBalance
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}