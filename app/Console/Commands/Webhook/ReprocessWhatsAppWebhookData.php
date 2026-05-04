<?php

namespace App\Console\Commands\Webhook;

use App\Jobs\ProcessStatusUpdate;
use Illuminate\Console\Command;
use App\Models\Report\Logdata;
use App\Models\Report\OutReport;
use App\Models\Campaign\CampaignReport;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ReprocessWhatsAppWebhookData extends Command
{

    protected $description = 'Reprocess WhatsApp webhook data from logdatas table for missed status updates (only unprocessed records where reprocessed_at IS NULL)';

    protected $signature = 'whatsapp:reprocess-webhook-data
                        {--hours=24 : Number of hours to look back}
                        {--limit=1000 : Maximum records to process}';

    public function handle()
    {
        $hours = $this->option('hours');
        $limit = $this->option('limit');

        $logDatas = Logdata::where('created_at', '>=', Carbon::now()->subHours($hours))
            ->whereNotNull('logs')
            ->whereNull('reprocessed_at')
            ->whereNotNull('message_id')
            ->whereNotNull('display_phone_number')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $this->info("Found {$logDatas->count()} unprocessed log records to check");

        $reprocessCount = 0;
        $skippedCount = 0;

        foreach ($logDatas as $logData) {
            try {
                $webhookData = $logData->logs;

                if (!$webhookData || !isset($webhookData['entry'])) {
                    $skippedCount++;
                    continue;
                }

                if (
                    isset($webhookData['entry'][0]['changes'][0]['value']['statuses'][0]['recipient_id']) &&
                    $webhookData['entry'][0]['changes'][0]['value']['statuses'][0]['recipient_id'] === '911234567905'
                ) {
                    $this->line("→ Full Status:\n" . json_encode($webhookData['entry'][0]['changes'][0]['value']['statuses'][0]['id'], JSON_PRETTY_PRINT));
                    $needs = $this->needsReprocessing($webhookData);
                    $this->line("needsReprocessing: " . ($needs ? 'true' : 'false'));
                }

                if ($this->needsReprocessing($webhookData)) {
                    $displayPhoneNumber = $webhookData['entry'][0]['changes'][0]['value']['metadata']['display_phone_number'] ?? null;
                    $this->line('displayPhoneNumber: ' . $displayPhoneNumber);
                    ProcessStatusUpdate::dispatch($webhookData, 'status-updates-15', $displayPhoneNumber)->onQueue('status-updates-15');
                    $logData->update(['reprocessed_at' => now()]);
                    $reprocessCount++;
                    $this->line("Reprocessing logdata ID: {$logData->id}");
                } else {
                    $skippedCount++;
                }

            } catch (\Exception $e) {
                $this->error("Error processing logdata ID {$logData->id}: " . $e->getMessage());
                Log::error('Reprocess webhook error', [
                    'logdata_id' => $logData->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->info("Completed! Records reprocessed: {$reprocessCount}, Records skipped: {$skippedCount}");
    }

    private function needsReprocessing(array $webhookData): bool
    {
        try {
            foreach ($webhookData['entry'] as $entry) {
                foreach ($entry['changes'] as $change) {
                    if ($change['field'] !== 'messages' || !isset($change['value']['statuses'])) {
                        continue;
                    }

                    $reportData = $change['value'];
                    $displayPhoneNumber = $reportData['metadata']['display_phone_number'] ?? null;

                    foreach ($reportData['statuses'] as $status) {
                        $statusId = $status['id'] ?? null;
                        $waId = $status['recipient_id'] ?? null;
                        $conversationId = $status['conversation']['id'] ?? null;
                        $webhookStatus = $status['status'] ?? null;

                        if (!$statusId || !$waId || !$webhookStatus) {
                            continue;
                        }

                        $this->line("WA ID: " . ($waId ?? 'null'));
                        $this->line('statusId: ' . $statusId);

                        if ($this->reportNeedsUpdate($statusId, $waId, $conversationId, $displayPhoneNumber, $webhookStatus)) {
                            $this->line('reportNeedsUpdate: true');
                            return true;
                        }
                    }
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Error checking reprocessing need', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function reportNeedsUpdate($statusId, $waId, $conversationId, $displayPhoneNumber, $webhookStatus): bool
    {
        $statusOrder = ['pending', 'sent', 'delivered', 'read', 'failed'];

        if (!in_array($webhookStatus, $statusOrder)) {
            $this->line("❌ Invalid webhook status '{$webhookStatus}' - skipping - Status ID: {$statusId}");
            return false;
        }

        // Use the direct fields from logdata for more efficient lookup
        // Check OutReport using message_id field from logdata
        $outReport = OutReport::where('status_id', $statusId)
            ->where('wa_id', $waId)
            ->where('billable', 0)
            ->first();

        if ($outReport) {
            $this->line("✅ Found OutReport ID: {$outReport->id}, Current Status: '{$outReport->status}', Webhook Status: '{$webhookStatus}'");
            return $this->shouldReprocessBasedOnStatus($outReport->status, $webhookStatus, $statusOrder, 'OutReport', $statusId, $waId);
        }

        // Check CampaignReport using message_id field from logdata
        $campaignReport = CampaignReport::where('message_id', $statusId)
            ->where('mobile_number', $waId)
          	 ->where('status', '!=', 'read')
            ->where('billable', 0)
            ->first();

        if ($campaignReport) {
            $this->line("✅ Found CampaignReport ID: {$campaignReport->id}, Current Status: '{$campaignReport->status}', Webhook Status: '{$webhookStatus}'");
            return $this->shouldReprocessBasedOnStatus($campaignReport->status, $webhookStatus, $statusOrder, 'CampaignReport', $statusId, $waId);
        }

        // Optional fallback by conversation (since we now have display_phone_number in logdata)
        if ($conversationId && $displayPhoneNumber) {
            $outReportByConv = OutReport::where('conversation_id', $conversationId)
                ->where('display_phone_number', $displayPhoneNumber)
              	->where('status', '!=', 'read')
                ->where('billable', 0)
                ->first();

            if ($outReportByConv) {
                $this->line("✅ Found OutReport (by Conv) ID: {$outReportByConv->id}, Current Status: '{$outReportByConv->status}', Webhook Status: '{$webhookStatus}'");
                return $this->shouldReprocessBasedOnStatus($outReportByConv->status, $webhookStatus, $statusOrder, 'OutReport (by Conv)', $conversationId, $displayPhoneNumber);
            }
        }

        $this->line("🟡 No matching report found - needs initial processing - Status ID: {$statusId}, WA ID: {$waId}, Webhook Status: '{$webhookStatus}'");
        return true;
    }

    private function shouldReprocessBasedOnStatus($currentStatus, $webhookStatus, $statusOrder, $reportType, $identifier, $waId): bool
    {
        // Special handling for 'failed' status
        if ($webhookStatus === 'failed') {
            // 'failed' can update 'pending' and 'sent' statuses
            if (in_array($currentStatus, ['pending', 'sent'])) {
                $this->line("✅ Failed status can update '{$currentStatus}' - needs reprocessing - {$reportType}: {$identifier}, WA ID: {$waId}");
                return true;
            } else {
                $this->line("❌ Failed status cannot update '{$currentStatus}' - skipping - {$reportType}: {$identifier}, WA ID: {$waId}");
                return false;
            }
        }

        // Handle normal status progression (excluding 'failed' from position-based logic)
        $normalStatusOrder = ['pending', 'sent', 'delivered', 'read'];

        $currentPosition = array_search($currentStatus, $normalStatusOrder);
        $webhookPosition = array_search($webhookStatus, $normalStatusOrder);

        // If current status is 'failed', it can be updated to any normal status
        if ($currentStatus === 'failed') {
            $this->line("✅ Current status is 'failed' - can be updated to '{$webhookStatus}' - needs reprocessing - {$reportType}: {$identifier}, WA ID: {$waId}");
            return true;
        }

        if ($currentPosition === false) {
            $this->line("❌ Unknown current status '{$currentStatus}' - needs reprocessing - {$reportType}: {$identifier}, WA ID: {$waId}");
            return true;
        }

        if ($webhookPosition === false) {
            $this->line("❌ Unknown webhook status '{$webhookStatus}' - skipping - {$reportType}: {$identifier}, WA ID: {$waId}");
            return false;
        }

        // Normal forward progression check
        if ($webhookPosition > $currentPosition) {
            $this->line("✅ Status can advance: '{$currentStatus}' -> '{$webhookStatus}' - needs reprocessing - {$reportType}: {$identifier}, WA ID: {$waId}");
            return true;
        }

        if ($webhookPosition == $currentPosition) {
            return $this->hasStatusGaps($currentStatus, $statusOrder, $reportType, $identifier, $waId);
        }

        $this->line("❌ Backward status detected: '{$currentStatus}' <- '{$webhookStatus}' - skipping - {$reportType}: {$identifier}, WA ID: {$waId}");
        return false;
    }

    private function hasStatusGaps($currentStatus, $statusOrder, $reportType, $identifier, $waId): bool
    {
        if ($currentStatus !== 'read') {
            $needsUpdate = false;

            if ($reportType === 'OutReport' || $reportType === 'OutReport (by Conv)') {
                $report = OutReport::where(function ($query) use ($identifier, $waId, $reportType) {
                    if ($reportType === 'OutReport') {
                        $query->where('status_id', $identifier)->where('wa_id', $waId);
                    } else {
                        $query->where('conversation_id', $identifier);
                    }
                })->first();

                $needsUpdate = $report && $report->billable == 0;
            } elseif ($reportType === 'CampaignReport') {
                $report = CampaignReport::where('message_id', $identifier)->where('mobile_number', $waId)->first();
                $needsUpdate = $report && $report->billable == 0;
            }

            if ($needsUpdate) {
                $this->line("✅ Status '{$currentStatus}' has billable=0 - needs reprocessing - {$reportType}: {$identifier}, WA ID: {$waId}");
                return true;
            }
        }

        $this->line("❌ Status already up to date: '{$currentStatus}' - no reprocessing needed - {$reportType}: {$identifier}, WA ID: {$waId}");
        return false;
    }
}
