<?php

// File: app/Services/CampaignProcessingService.php
namespace App\Services\Campaign;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\Billing\BalanceService;
use Illuminate\Support\Facades\Log;

class CampaignProcessingService
{
    protected $balanceService;

    public function __construct(BalanceService $balanceService)
    {
        $this->balanceService = $balanceService;
    }

    /**
     * Process campaigns within time range
     */
    public function processCampaignsInTimeRange()
    {
        $campaigns = $this->getCampaignsInTimeRange();

        if ($campaigns->isEmpty()) {
            return [
                'success' => true,
                'message' => 'No campaigns found in the specified time range.',
                'data' => [
                    'total_campaigns' => 0,
                    'processed_campaigns' => 0,
                    'updated_records' => 0
                ]
            ];
        }

        $processedCampaigns = 0;
        $totalUpdatedRecords = 0;
        $campaignResults = [];

        foreach ($campaigns as $campaign) {
            $result = $this->processCampaign($campaign->id);
            if ($result['processed']) {
                $processedCampaigns++;
                $totalUpdatedRecords += $result['updated_records'];
                $campaignResults[] = $result;
            }
        }

        return [
            'success' => true,
            'message' => 'Campaign processing completed.',
            'data' => [
                'total_campaigns' => $campaigns->count(),
                'processed_campaigns' => $processedCampaigns,
                'updated_records' => $totalUpdatedRecords,
                'campaign_results' => $campaignResults
            ]
        ];
    }

    /**
     * Process specific campaign by ID
     */
    public function processCampaignById($campaignId)
    {
        // Check if campaign exists
        $campaign = DB::table('campaigns')->where('id', $campaignId)->first();

        if (!$campaign) {
            return [
                'success' => false,
                'message' => 'Campaign not found.',
                'data' => null
            ];
        }

        $result = $this->processCampaign($campaignId);

        return [
            'success' => true,
            'message' => $result['processed'] ? 'Campaign processed successfully.' : 'Campaign does not meet processing criteria.',
            'data' => $result
        ];
    }

    /**
     * Get campaigns in time range (4-24 hours old)
     */
    private function getCampaignsInTimeRange()
    {
        $twentyFourHoursAgo = Carbon::now()->subHours(24);
        $fourHoursAgo = Carbon::now()->subHours(4);

        return DB::table('campaigns')
            ->where('created_at', '<=', $fourHoursAgo)
            ->where('created_at', '>=', $twentyFourHoursAgo)
            ->get();
    }

    /**
     * Process individual campaign
     */
    public function processCampaign($campaignId)
    {
        // Get campaign reports for this campaign
        //$campaignReports = $this->getCampaignReports($campaignId);

        //if ($campaignReports->isEmpty()) {
        //    return [
        //        'campaign_id' => $campaignId,
         //       'processed' => false,
        //        'reason' => 'No campaign reports found',
         //       'sent_percentage' => 0,
        //        'updated_records' => 0
        //    ];
        //}

        // Process campaign reports
        $updatedRecords = $this->updateCampaignReportsStatus($campaignId);
	
        return [
            'campaign_id' => $campaignId,
            'processed' => true,
            'reason' => 'Successfully processed',
            //'sent_percentage' => $sentPercentage,
            'updated_records' => $updatedRecords
        ];
    }

    /**
     * Get campaign reports for a specific campaign
     */
    private function getCampaignReports($campaignId)
    {
        return DB::table('campaign_reports')
          	->limit(100)
            ->where('campaign_id', $campaignId)
            ->get();
    }

    /**
     * Calculate percentage of reports with "sent" status
     */
    private function calculateSentPercentage($campaignReports)
    {
        $totalReports = $campaignReports->count();

        if ($totalReports === 0) {
            return 0;
        }
      
		 $sentReports = $campaignReports->filter(function ($report) {
            return in_array($report->status, ['sent', 'pending']);
        })->count();
        //$sentReports = $campaignReports->where('status', 'sent')->count();

        return round(($sentReports / $totalReports) * 100, 2);
    }

    /**
     * Update campaign reports status based on logdatas
     */
    private function updateCampaignReportsStatus($campaignId)
    {
        // Get updatable reports (exclude final statuses)
        $updatableReports = DB::table('campaign_reports')
            ->where('campaign_id', $campaignId)
            ->where(function ($query) {
                $query->where('status', '!=', 'read')
                    ->where('status', '!=', 'failed');
            })
          	->limit(500)
            ->get();

        if ($updatableReports->isEmpty()) {
            return 0;
        }

        $updatedCount = 0;

        foreach ($updatableReports as $report) {
            $result = $this->updateReportStatus($report);

            if ($result) {
                $updatedCount++;
            }
        }

        return $updatedCount;
    }

    /**
     * Update individual report status
     */
    private function updateReportStatus($report)
    {
        // Get all available statuses from logdatas for this message_id
        $availableLogs = DB::table('logdatas')
             ->where('message_id', $report->message_id)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($availableLogs->isEmpty()) {
            return false;
        }

        // Get the best possible status based on current status and available logs
        $newStatus = $this->getBestAvailableStatus($report->status, $availableLogs);

        // If no valid status update found
        if (!$newStatus || $newStatus === $report->status) {
            return false;
        }

        // Update the record
        $this->updateCampaignReport($report->id, $newStatus, $report->message_id);

        // Handle balance deduction
        $deductionCount = 0;
        foreach ($availableLogs as $availableLog) {
            if ($newStatus === 'sent' || $newStatus === 'delivered') {
                $result = $this->deductBalance($availableLog, $report);
                if ($result) {
                    $deductionCount++;
                }
            }
        }

        return true;
    }

    /**
     * Get the best available status based on progression rules
     */
    private function getBestAvailableStatus($currentStatus, $availableLogs)
    {
        // Convert logs to array of statuses
        $availableStatuses = $availableLogs->pluck('status')->toArray();

        // Define allowed progressions
        $allowedProgressions = [
            'pending' => ['failed', 'sent'],
            'sent' => ['failed', 'delivered', 'read'],
            'delivered' => ['read']
        ];

        // Check if current status can be updated
        if (!isset($allowedProgressions[$currentStatus])) {
            return null;
        }

        // Special logic based on current status
        switch ($currentStatus) {
            case 'pending':
                if (in_array('failed', $availableStatuses)) {
                    return 'failed';
                }
                if (in_array('sent', $availableStatuses)) {
                    return 'sent';
                }
                break;

            case 'sent':
                if (in_array('failed', $availableStatuses)) {
                    return 'failed';
                }
                if (in_array('read', $availableStatuses)) {
                    return 'read';
                }
                if (in_array('delivered', $availableStatuses)) {
                    return 'delivered';
                }
                break;

            case 'delivered':
                if (in_array('read', $availableStatuses)) {
                    return 'read';
                }
                break;
        }

        return null;
    }

    /**
     * Update campaign report record
     */
    private function updateCampaignReport($reportId, $newStatus, $messageId)
    {
        $updateData = [
            'status' => $newStatus,
            'updated_at' => Carbon::now(),
        ];

        DB::table('campaign_reports')
            ->where('id', $reportId)
            ->update($updateData);
    }

    /**
     * Handle balance deduction
     */

    private function deductBalance($log, $report)
    {
      	$data = is_string($log->logs) ? json_decode($log->logs, true) : $log->logs;
        // Validate log data exists
        if (!$log || !isset($log->logs)) {
            Log::warning("Log data is missing for message_id: {$report->message_id}");
            return false;
        }

        // Decode JSON if necessary
        $data = is_string($log->logs) ? json_decode($log->logs, true) : $log->logs;

        // Validate decoded data
        if (!$data) {
            Log::warning("Failed to decode log data for message_id: {$report->message_id}");
            return false;
        }

        // Safely extract report data with null checks
        if (!isset($data['entry']) || !is_array($data['entry']) || empty($data['entry'])) {
            Log::warning("Missing 'entry' data for message_id: {$report->message_id}");
            return false;
        }

        if (!isset($data['entry'][0]['changes']) || !is_array($data['entry'][0]['changes']) || empty($data['entry'][0]['changes'])) {
            Log::warning("Missing 'changes' data for message_id: {$report->message_id}");
            return false;
        }

        if (!isset($data['entry'][0]['changes'][0]['value'])) {
            Log::warning("Missing 'value' data for message_id: {$report->message_id}");
            return false;
        }

        $reportData = $data['entry'][0]['changes'][0]['value'];
        $reportId = $report->id;

        try {
            // Call the BalanceService to handle conversation and deduct balance
            return $this->balanceService->handleConversation($reportData, null, $reportId);
        } catch (\Exception $e) {
            Log::error("Error in handleConversation for report_id: {$reportId}, error: " . $e->getMessage());
            return false;
        }
    }
}
