<?php

namespace App\Console\Commands\Campaign;

use App\Models\Campaign\CampaignReport;
use Illuminate\Console\Command;

class UpdatePendingToFailed extends Command
{
    /**
     * The name and signature of the console command.
     * @var string
     *
     */
    protected $signature = 'pending-to-failed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'pending-to-failed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $records = CampaignReport::where('status', 'pending')
            ->where('created_at', '<', now()->subDay())
            ->get();

        foreach ($records as $record) {
            $record->update(['status' => 'failed']);
        }
      	 $count = $records->count();
        $this->info('Successfully updated pending records to failed.');
    }
}
