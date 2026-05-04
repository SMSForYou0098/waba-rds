<?php
namespace App\Console\Commands\Campaign;

use App\Services\Campaign\CampaignProcessingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\Billing\BalanceService;
class ProcessCampaigns extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'campaigns:process-status';

    /**
     * The console command description.
     */
    protected $description = 'Process campaigns older than 4 hours but not older than 24 hours with 25% sent status';

    /**
     * Execute the console command.
     */

    protected $campaignProcessingService;
    public function __construct(CampaignProcessingService $campaignProcessingService)
    {
        parent::__construct();
        $this->campaignProcessingService = $campaignProcessingService;
    }

    public function handle()
    {
        $this->info('Starting campaign processing...');

        // Use the service to process campaigns
        $result = $this->campaignProcessingService->processCampaignsInTimeRange();

        // Display results
        $this->info($result['message']);

        if ($result['success'] && isset($result['data'])) {
            $data = $result['data'];
            $this->info("Found {$data['total_campaigns']} campaigns to process.");

            if ($data['processed_campaigns'] > 0) {
                // $this->info("Processed {$data['processed_campaigns']} campaigns.");
                // $this->info("Updated {$data['updated_records']} status records.");

                // // Show individual campaign results
                // foreach ($data['campaign_results'] as $campaignResult) {
                //     $this->info("Campaign ID {$campaignResult['campaign_id']}: Updated {$campaignResult['updated_records']} records (has {$campaignResult['sent_percentage']}% sent status)");
                // }
            }
        }

        $this->info('Campaign processing completed.');
    }
}
