<?php
namespace App\Console\Commands\Media;
use App\Models\Report\Report;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;
use App\Models\Media\Media;
use Storage;

class DeleteOldMediaFiles extends Command
{
    // Command signature and description
    protected $signature = 'files:delete-old-media';
    protected $description = 'Soft delete media files older than 30 days (keeps physical files for restore)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->DeleteRegularMedia();
        $this->DeleteReportsMedia();
    }

    private function DeleteRegularMedia()
    {
        $oldFiles = Media::where(function ($query) {
            $date = Carbon::now()->subDays(30);
            $query->where(function ($q) use ($date) {
                $q->whereNotNull('updated_at')
                    ->where('updated_at', '<', $date);
            })->orWhere(function ($q) use ($date) {
                $q->whereNull('updated_at')
                    ->where('created_at', '<', $date);
            });
        })->get();

        if ($oldFiles->isEmpty()) {
            $this->info('No old media files to delete.');
            return;
        }

        foreach ($oldFiles as $fileRecord) {
            // Only soft delete the record, keep the physical file for restore
            $fileRecord->delete_type = 'auto';
            $fileRecord->save();
            
            // Soft delete the record
            $fileRecord->delete();
            $this->info("Soft deleted record from database: {$fileRecord->id} (physical file retained)");
        }

        $this->info('Old media records have been soft deleted successfully. Physical files retained for restore.');
    }

    private function DeleteReportsMedia()
    {
        $directoryPath = 'reports_media';
        $files = Storage::disk('uploads')->allFiles($directoryPath);
        $count = 0;

        foreach ($files as $file) {
            $lastModified = Storage::disk('uploads')->lastModified($file);
            if (Carbon::now()->subHours(48)->gt(Carbon::createFromTimestamp($lastModified))) {
                // For reports, you might want to keep files too
                // If reports don't need restore, keep the physical delete
                Storage::disk('uploads')->delete($file);
                
                // Update reports to set media_url to null
                Report::where('media_url', 'like', '%' . $file . '%')->update(['media_url' => null]);
                
                $count++;
            }
        }

        if ($count > 0) {
            $this->info('Report media files older than 48 hours and corresponding media_urls have been deleted.');
        } else {
            $this->info("No old report files found.");
        }
    }
}