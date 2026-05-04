<?php

namespace App\Console\Commands\Webhook;

use App\Jobs\ProcessStatusUpdate;
use App\Models\Report\Logdata;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Carbon\Carbon;

class ProcessWebhookData extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'webhook:process
                           {--batch-size=100 : Number of records to process per batch}
                           {--max-jobs=500 : Maximum jobs to dispatch per run}
                           {--queue=default : Queue name to dispatch jobs to}
                           {--hours-back=24 : Process records from last X hours}
                           {--force : Force process even if system load is high}';

    /**
     * The console command description.
     */
    protected $description = 'Process webhook data from logdatas table and dispatch to ProcessStatusUpdate jobs (Optimized)';

    private $batchSize;
    private $maxJobs;
    private $queueName;
    private $hoursBack;
    private $processedCount = 0;
    private $errorCount = 0;
    private $skippedCount = 0;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->batchSize = (int) $this->option('batch-size');
        $this->maxJobs = (int) $this->option('max-jobs');
        $this->queueName = $this->option('queue');
        $this->hoursBack = (int) $this->option('hours-back');

        //$this->info("🚀 Starting optimized webhook data processing...");
        //$this->info("📊 Config: Batch Size: {$this->batchSize}, Max Jobs: {$this->maxJobs}, Queue: {$this->queueName}");

        // Check system load before processing (unless forced)
        if (!$this->option('force') && $this->isSystemOverloaded()) {
            $this->warn("⚠️  System load too high, skipping this run");
            return 1;
        }

        $startTime = microtime(true);

        try {
            $this->processWebhookDataOptimized();

            $duration = round(microtime(true) - $startTime, 2);
            $this->displaySummary($duration);

            return 0;
        } catch (\Exception $e) {
            $this->error("❌ Fatal error: " . $e->getMessage());
            \Log::error('Webhook processing failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * High-performance processing method using SQL grouping
     */
    private function processWebhookDataOptimized()
    {
        $cutoffTime = Carbon::now()->subHours($this->hoursBack);
        $jobsDispatched = 0;

        //$this->info("📅 Processing records since: {$cutoffTime->format('Y-m-d H:i:s')} (OPTIMIZED MODE)");

        // Get grouped data with counts for better planning
        $phoneNumberStats = DB::table('logdatas')
            ->select('display_phone_number', DB::raw('COUNT(*) as record_count'))
            ->whereNull('reprocessed_at')
            ->where('created_at', '>=', $cutoffTime)
            ->whereNotNull('logs')
            ->whereNotNull('display_phone_number')
            ->groupBy('display_phone_number')
            ->orderBy('record_count', 'desc') // Process highest volume first
            ->get();

        //$this->info("📱 Found " . $phoneNumberStats->count() . " unique phone numbers");
        //$this->info("📊 Total records to process: " . $phoneNumberStats->sum('record_count'));

        foreach ($phoneNumberStats as $stat) {
            if ($jobsDispatched >= $this->maxJobs) {
                $this->warn("⚡ Reached max jobs limit ({$this->maxJobs}), stopping");
                break;
            }

            $phoneNumber = $stat->display_phone_number;
            $recordCount = $stat->record_count;

            $this->info("📞 Processing {$phoneNumber} ({$recordCount} records)");

            // Calculate optimal batch size for this phone number
            $optimalBatchSize = min($this->batchSize, max(10, $recordCount / 5));

            // Process this phone number's records
            $processedForPhone = 0;
            Logdata::query()
                ->whereNull('reprocessed_at')
                ->where('created_at', '>=', $cutoffTime)
                ->whereNotNull('logs')
              	//->where('display_phone_number', 918000565555)
                ->where('display_phone_number', $phoneNumber)
                ->select(['id', 'logs', 'display_phone_number', 'message_id', 'created_at'])
                ->orderBy('created_at', 'asc')
                ->chunk($optimalBatchSize, function ($logdatas) use (&$jobsDispatched, &$processedForPhone, $phoneNumber) {
                    if ($jobsDispatched >= $this->maxJobs) {
                        return false;
                    }

                    $batchProcessed = $this->processBatchGrouped($logdatas, $jobsDispatched, $phoneNumber);
                    $processedForPhone += $batchProcessed;

                    usleep(2000); // 2ms delay between batches
                });

            //$this->line("  ✅ {$phoneNumber}: {$processedForPhone} total records processed");
            usleep(10000); // 10ms delay between phone numbers
        }
    }

    private function processBatchGrouped($logdatas, &$jobsDispatched, $phoneNumber)
    {
        $batchIds = [];
        $dispatchData = [];

        // Since all records in this batch have the same phone number, we can optimize further
        //$this->line("  📦 Processing batch of " . $logdatas->count() . " records for {$phoneNumber}");

        foreach ($logdatas as $logdata) {
            if ($jobsDispatched >= $this->maxJobs) {
                break;
            }

            try {
                // Decode the JSON log data
                $webhookData = is_string($logdata->logs) ? json_decode($logdata->logs, true) : $logdata->logs;

                if (!$this->isValidWebhookData($webhookData)) {
                    $this->skippedCount++;
                    $batchIds[] = $logdata->id; // Mark as processed even if skipped
                    continue;
                }

                // Since we're grouping by phone number, we know this is consistent
                $displayPhoneNumber = $phoneNumber; // Use the grouped phone number
				
                // Prepare job dispatch data
                $dispatchData[] = [
                    'logdata_id' => $logdata->id,
                    'webhook_data' => $webhookData,
                    'display_phone_number' => $displayPhoneNumber,
                    'queue_name' => $this->queueName
                ];

                $batchIds[] = $logdata->id;
                $jobsDispatched++;

            } catch (\Exception $e) {
                $this->errorCount++;
                $this->warn("⚠️  Error processing logdata ID {$logdata->id}: " . $e->getMessage());
                $batchIds[] = $logdata->id; // Mark as processed to avoid reprocessing
            }
        }

        // Dispatch all jobs in this batch (all for the same phone number)
        if (!empty($dispatchData)) {
            $this->dispatchJobsGrouped($dispatchData, $phoneNumber);
        }

        // Mark records as being reprocessed (set reprocessed_at timestamp)
        if (!empty($batchIds)) {
            $this->markAsReprocessed($batchIds);
        }

        $this->processedCount += count($batchIds);

        // Progress indicator with phone number context
        //$this->line("    ✅ {$phoneNumber}: " . count($dispatchData) . " jobs dispatched, " . count($batchIds) . " records marked");

        return count($batchIds); // Return processed count for tracking
    }

    private function dispatchJobsGrouped($dispatchData, $phoneNumber)
    {
        $jobCount = count($dispatchData);
        //$this->line("    🚀 Dispatching {$jobCount} jobs for {$phoneNumber}");

        $workerCount = 15; // status-updates-0 to status-updates-14

        foreach ($dispatchData as $i => $data) {
            try {
                // Round-robin: 0,1,2...14,0,1,2...
                $queueIndex = $i % $workerCount;
                $queueName = "status-updates-{$queueIndex}";
				$this->line("  ✅webhookData => {$phoneNumber}: " . json_encode($data['webhook_data']));
                ProcessStatusUpdate::dispatch(
                    $data['webhook_data'],
                    $data['queue_name'],
                    $data['display_phone_number']
                )->onQueue($queueName);

            } catch (\Exception $e) {
                $this->error("❌ Failed to dispatch job for logdata ID {$data['logdata_id']}: " . $e->getMessage());
                $this->errorCount++;
            }
        }
    }

    private function markAsReprocessed($logdataIds)
    {
        try {
            // Batch update for efficiency
            //Logdata::whereIn('id', $logdataIds)
            //    ->update(['reprocessed_at' => now()]);
        } catch (\Exception $e) {
            $this->error("❌ Failed to mark records as reprocessed: " . $e->getMessage());
        }
    }

    private function isValidWebhookData($webhookData)
    {
        // Validate webhook data structure
        if (!is_array($webhookData)) {
            return false;
        }

        // Check for required webhook structure
        if (!isset($webhookData['entry'][0]['changes'][0]['value'])) {
            return false;
        }

        $reportData = $webhookData['entry'][0]['changes'][0]['value'];

        // Must have status data
        if (!isset($reportData['statuses'][0])) {
            return false;
        }

        $status = $reportData['statuses'][0];

        // Required fields
        if (empty($status['status']) || empty($status['id'])) {
            return false;
        }

        return true;
    }

    private function isSystemOverloaded()
    {
        // Check queue size
        $queueSize = Queue::size($this->queueName);
        if ($queueSize > 1000) {
            return true;
        }

        // Check system load on Unix systems
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load[0] > 2.0) { // Adjust threshold as needed
                return true;
            }
        }

        return false;
    }

    private function displaySummary($duration)
    {
        //$this->info("\n📈 Processing Summary:");
        //$this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        //$this->info("✅ Total Records Processed: " . $this->processedCount);
        //$this->info("🚀 Jobs Successfully Dispatched: " . ($this->processedCount - $this->skippedCount - $this->errorCount));
        //$this->info("⚠️  Records Skipped: " . $this->skippedCount);
        //$this->info("❌ Errors Encountered: " . $this->errorCount);
        //$this->info("⏱️  Execution Time: {$duration} seconds");
         //       $this->info(
        //    "📊 Processing Rate: " .
        //    ($duration > 0
       //         ? round($this->processedCount / $duration, 2) . " records/second"
       //         : "N/A (duration too short)"
       //     )
       // );
        //$this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

       // if ($this->processedCount > 0) {
       //     $this->info("🎉 Webhook processing completed successfully!");
       // } else {
       //     $this->warn("ℹ️  No records found to process");
       // }
    }
}
