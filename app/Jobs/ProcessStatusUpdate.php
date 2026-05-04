<?php

namespace App\Jobs;


use App\Models\Campaign\CampaignReport;
use App\Models\Report\OutReport;
use App\Models\QueueStat;
use App\Models\User;
use App\Models\Report\Logdata;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessStatusUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;
    protected $displayPhoneNumber;
    protected $queueName;
    public $timeout = 120;

    // Cache user data to avoid repeated queries
    private static $userCache = [];
    private static $pricingCache = [];

    public function __construct($data, $queueName, $displayPhoneNumber)
    {
        $this->data = $data;
        $this->queueName = $queueName;
        $this->displayPhoneNumber = $displayPhoneNumber;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('ProcessStatusUpdate job dispatched', [
                'queue' => $this->queueName,
                'display_phone_number' => $this->displayPhoneNumber,
                'job_id' => $this->job ? $this->job->getJobId() : null,
                'data_id' => $this->data['entry'][0]['id'] ?? null
            ]);
            $this->processStatusUpdates($this->data);
        } catch (\Exception $e) {
            //Log::error('Error processing status update: ' . $e->getMessage());
        } finally {
            // Use updateOrDecrement for atomic operation - faster than select + update
            QueueStat::where('display_phone_number', $this->displayPhoneNumber)
                ->where('queue_name', $this->queueName)
                ->where('pending_request', '>', 0)
                ->decrement('pending_request');
        }
    }

    private function processStatusUpdates($data)
    {
        try {
            $reportData = $data['entry'][0]['changes'][0]['value'];

            if (!isset($reportData['statuses'][0])) {
                Log::error('Invalid webhook data structure: missing statuses');
                return;
            }

            $statusData = $reportData['statuses'][0];
            $status = $statusData['status'];
            $id = $statusData['id'];
            $conversation = $statusData['conversation']['id'] ?? null;
            $expireTime = $statusData['conversation']['expiration_timestamp'] ?? null;
            $error_code = $statusData['errors'][0]['code'] ?? null;
            $recipientId = $statusData['recipient_id'] ?? null;
            $displayPhoneNumber = $reportData['metadata']['display_phone_number'];

            if (!$id || !$status || !$displayPhoneNumber) {
                Log::error('Missing essential webhook data', compact('id', 'status', 'displayPhoneNumber'));
                return;
            }

            // Get user data with caching
            $user = $this->getCachedUser($displayPhoneNumber);
            if (!$user) {
                Log::error("User not found for phone number: {$displayPhoneNumber}");
                return;
            }

            // Find existing report with optimized query
            $existingReport = $this->findExistingReportOptimized($id, $displayPhoneNumber, $conversation, $recipientId);

            if (!$existingReport) {
                Log::warning("No existing report found for message ID: {$id}");
                return;
            }

            $isBillable = false;
            if ($status == 'sent' || $status == 'delivered') {
                $paid = $statusData['pricing']['type'] == 'regular';
                $isBillable = $paid;
            }

            $this->updateExistingReportOptimized($existingReport, $reportData, $status, $conversation, $expireTime, $isBillable, $error_code, $user);
        } catch (\Exception $e) {
            Log::error('Error in processStatusUpdates: ' . $e->getMessage(), [
                'data' => $data,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    // Cache user data to avoid repeated database queries
    private function getCachedUser($displayPhoneNumber)
    {
        $cacheKey = "user_data_{$displayPhoneNumber}";

        // Check memory cache first
        if (isset(self::$userCache[$cacheKey])) {
            return self::$userCache[$cacheKey];
        }

        // Check Redis/file cache
        $user = Cache::remember($cacheKey, 300, function () use ($displayPhoneNumber) {
            return User::where('whatsapp_number', $displayPhoneNumber)
                ->with(['balance' => function ($query) {
                    $query->latest()->limit(1);
                }, 'pricingModel'])
                ->first();
        });

        // Store in memory cache for this request
        self::$userCache[$cacheKey] = $user;

        return $user;
    }

    // Optimized report finding - single query instead of multiple
    private function findExistingReportOptimized($messageId, $displayPhoneNumber, $conversationId, $recipientId)
    {
        // Single query using UNION to check both tables at once
        $query = "
            (SELECT id, 'out_report' as report_type, conversation_id, status, billable
             FROM out_reports
             WHERE conversation_id = ? AND display_phone_number = ? AND status != 'read' AND billable = 0
             LIMIT 1)
            UNION ALL
            (SELECT id, 'campaign_report' as report_type, conversation_id, status, billable
             FROM campaign_reports
             WHERE conversation_id = ? AND display_phone_number = ? AND status != 'read' AND billable = 0
             LIMIT 1)
            LIMIT 1
        ";

        $result = DB::select($query, [$conversationId, $displayPhoneNumber, $conversationId, $displayPhoneNumber]);

        if (!empty($result)) {
            $row = $result[0];

            if ($row->report_type === 'out_report') {
                $report = OutReport::find($row->id);
                return ['type' => 'out_report', 'report' => $report];
            } else {
                $report = CampaignReport::find($row->id);
                return ['type' => 'campaign_report', 'report' => $report];
            }
        }

        return null;
    }

    private function updateExistingReportOptimized($existingReport, $reportData, $status, $conversationId, $expireTime, $isBillable, $error_code, $user)
    {
        if (!$existingReport) {
            return;
        }

        $reportType = $existingReport['type'];
        $report = $existingReport['report'];

        switch ($reportType) {
            case 'out_report':
                $this->updateOutReportOptimized($report, $reportData, $status, $isBillable, $error_code, $user);
                break;

            case 'campaign_report':
                $this->updateCampaignStatusOptimized($report, $reportData, $status, $conversationId, $expireTime, $isBillable, $error_code, $user);
                break;

            default:
                Log::error("Unknown report type: {$reportType}");
                break;
        }
    }

    private function updateCampaignStatusOptimized($report, $reportData, $status, $conversationId, $expireTime, $isBillable, $error_code, $user)
    {
        $reportId = $report->id;
        $id = $reportData['statuses'][0]['id'];

        // Build update data array
        $updateData = [];

        if ($report->status != 'read') {
            $updateData['status'] = $status;
        }

        if ($conversationId) {
            $updateData['conversation_id'] = $conversationId;
        }

        if ($expireTime) {
            $updateData['expiration_timestamp'] = $expireTime;
        }

        if ($status == 'failed') {
            $updateData['error_code'] = $error_code;
        }

        // Handle billing logic
        $shouldBill = false;
        if ($report->billable != 1 && $isBillable) {
            $shouldBill = true;
            $updateData['billable'] = 1;
        }

        if (($status == 'read' || $status == 'delivered') && $report->conversation_id == null && $report->billable != 1) {
            $shouldBill = true;
            $updateData['billable'] = 1;
        }

        // Perform single update query if there's data to update
        if (!empty($updateData)) {
            CampaignReport::where('id', $reportId)->update($updateData);
        }

        // Handle conversation billing
        if ($shouldBill) {
            $this->handleConversationOptimized($reportData, $user, $reportId);
        }

        // Update logdata asynchronously to avoid blocking
        $this->updateLogdatasReprocessedAtAsync($id);
    }

    private function updateOutReportOptimized($outReport, $reportData, $status, $isBillable, $error_code, $user)
    {
        $timestamp = $reportData['statuses'][0]['timestamp'] ?? null;
        $reportId = $outReport->id;
        $id = $reportData['statuses'][0]['id'];

        // Convert timestamp only once if needed
        $formattedTimestamp = null;
        if ($timestamp) {
            $formattedTimestamp = Carbon::createFromTimestamp($timestamp)->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s');
        }

        // Build update data array
        $updateData = [];

        if ($outReport->status != 'read') {
            $updateData['status'] = $status;
            $updateData['timestamp'] = $formattedTimestamp;
            $updateData['recipient_id'] = $reportData['statuses'][0]['recipient_id'];

            if ($status === 'delivered') {
                $updateData['delivered_time'] = $formattedTimestamp;
            }

            if ($status === 'read') {
                $updateData['read_time'] = $formattedTimestamp;
            }

            if ($status == 'failed') {
                $updateData['error_code'] = $error_code;
            }

            // Handle conversation data if present
            if (isset($reportData['statuses'][0]['conversation'])) {
                $conversation = $reportData['statuses'][0]['conversation'];
                $updateData['conversation_id'] = $conversation['id'];
                $updateData['billable'] = $reportData['statuses'][0]['pricing']['billable'];
                $updateData['pricing_model'] = $reportData['statuses'][0]['pricing']['pricing_model'];
                $updateData['category'] = $reportData['statuses'][0]['pricing']['category'];
                if (isset($conversation['expiration_timestamp'])) {
                    $updateData['expiration_timestamp'] = $conversation['expiration_timestamp'];
                }
            }
        }

        // Handle billing logic
        $shouldBill = false;
        if ($outReport->billable != 1 && $isBillable) {
            $shouldBill = true;
            $updateData['billable'] = 1;
        }

        if (($status == 'read' || $status == 'delivered') && $outReport->conversation_id == null && $outReport->billable != 1) {
            $shouldBill = true;
            $updateData['billable'] = 1;
        }

        // Perform single update query if there's data to update
        if (!empty($updateData)) {
            OutReport::where('id', $reportId)->update($updateData);
        }

        // Handle conversation billing
        if ($shouldBill) {
            $this->handleConversationOptimized($reportData, $user, $reportId);
        }

        // Update logdata asynchronously
        $this->updateLogdatasReprocessedAtAsync($id);
    }

    private function handleConversationOptimized($reportData, $user, $reportId)
    {
        if (!$user || !isset($reportData['statuses'][0]['conversation'])) {
            return false;
        }

        $conversation = $reportData['statuses'][0]['conversation'];
        if (!isset($conversation['origin']['type'])) {
            return false;
        }

        $originType = $conversation['origin']['type'] . '_price';

        // Get pricing from cache
        $pricingCacheKey = "pricing_{$user->id}";
        if (!isset(self::$pricingCache[$pricingCacheKey])) {
            self::$pricingCache[$pricingCacheKey] = $user->pricingModel;
        }

        $price = self::$pricingCache[$pricingCacheKey]->$originType ?? 0;

        // Get latest balance more efficiently
        $currentBalance = $user->balance->first()->total_credits ?? 0;
        $newBalance = $currentBalance - $price;

        if ($newBalance >= 0) {
            // Insert balance record directly without model overhead
            DB::table('balances')->insert([
                'user_id' => $user->id,
                'new_credit' => $price,
                'report_id' => $reportId,
                'total_credits' => $newBalance,
                'payment_type' => 'cash',
                'account_manager_id' => $user->reporting_user,
                'auto_deduction' => 'true',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return true;
        } else {
            Log::warning("Insufficient balance for user {$user->id}. Required: {$price}, Available: {$currentBalance}");
            return false;
        }
    }

    // Make logdata update asynchronous to avoid blocking the main job
    private function updateLogdatasReprocessedAtAsync($messageId)
    {
        // Dispatch as a separate job or use defer() for Laravel 11+
        // For now, making it non-blocking with a simple update
        dispatch(function () use ($messageId) {
            try {
                Logdata::where('message_id', $messageId)->update(['reprocessed_at' => now()]);
            } catch (\Throwable $e) {
                Log::error("Exception updating logdata for message {$messageId}: " . $e->getMessage());
            }
        })->onQueue('low-priority');
    }
}
