<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Cleanup\CleanUpRecord;
use Illuminate\Http\Request;
use App\Models\Report\Report;
use App\Models\Report\OutReport;
use App\Models\Campaign\Campaign;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class DataCleanupController extends Controller
{
    /**
     * Create a record of cleanup operation
     */
    private function createCleanupRecord($tableName, $action_by, $type, $count, $userId, $dateRange, $filepath = null, $filename = null)
    {
        $data = [
            'table_name' => $tableName,
            'action' => $type,
            'action_by' => $action_by,
            'count' => $count,
            'user_id' => $userId,
            'before_date' => $dateRange['end_date'],
            'date_range' => $dateRange['start_date'], // Changed from array to string
        ];

        // Add file info if this is an export operation
        if ($type === 'export' && $filepath && $filename) {
            $data['link'] = $filepath;
            $data['file_name'] = $filename;
        }

        return CleanUpRecord::create($data);
    }

    protected function storeFile($file, $storageDisk = 'uploads')
    {
        $path = $file->store('exports', $storageDisk);
        $url = Storage::disk($storageDisk)->url($path);
        return $url;
    }
    /**
     * Get date range based on predefined option or custom date
     */
    private function exportDataToCsv($data, $tableName, $action_by, $userId, $dateRange, $filePrefix = '')
    {
        if ($data->isEmpty()) {
            throw new \Exception('No data to export');
        }

        // Prepare data for CSV export
        $filename = ($filePrefix ?: $tableName) . '_export_' . now()->format('Y-m-d_His') . '.csv';
        $tempPath = storage_path('app/exports/temp_' . $filename);
        $file = fopen($tempPath, 'w');

        // Add headers
        fputcsv($file, array_keys($data->first()->toArray()));

        // Add rows
        foreach ($data as $item) {
            fputcsv($file, $item->toArray());
        }

        fclose($file);

        // Create file object from temp file
        $fileObject = new \Illuminate\Http\UploadedFile(
            $tempPath,
            $filename,
            'text/csv',
            null,
            true
        );

        // Store file using the storeFile method
        $url = $this->storeFile($fileObject);

        // Store record in clean_up_records table
        $this->createCleanupRecord(
            $tableName,
            $action_by,
            'export',
            $data->count(),
            $userId,
            $dateRange,
            $url,
            $filename
        );

        // Remove temporary file
        @unlink($tempPath);

        return $url;
    }
 	private function getDateRange(Request $request)
    {
        $beforeDate = $request->input('before_date');
        $timePeriod = $request->input('time_period') == 'true';
        $customDateRange = $request->input('date_range');

        // Default end date (before_date)
        $endDate = $beforeDate ? Carbon::parse($beforeDate) : Carbon::now();

        // Default start date (3 months ago if no option selected)
         $startDate = Carbon::parse('1970-01-01');
        // Process time period options
        if ($timePeriod) {
            switch ($customDateRange) {
                // New "Older Than" options
                case 'olderThanCurrentMonth':
                    // Records older than the start of the current month
                    $endDate = Carbon::now()->startOfMonth();
                    break;
                case 'olderThan3Months':
                    // Records older than 3 months ago
                    $endDate = Carbon::now()->subMonths(3);
                    break;
                case 'olderThan6Months':
                    // Records older than 6 months ago
                    $endDate = Carbon::now()->subMonths(6);
                    break;
                case 'olderThan1Year':
                    // Records older than 1 year ago
                	$endDate = Carbon::now()->subYear(1);
                    //$endDate = Carbon::now()->subYear();
                    break;
                default:
                    // Default to records older than 3 months
                    $endDate = Carbon::now()->subMonths(3);
            }
        } elseif ($customDateRange) {
            try {
                // If custom date range is provided
                $startDate = Carbon::parse($customDateRange);
            } catch (\Exception $e) {
                // If parsing fails, fallback to default
                $startDate = Carbon::now()->subMonths(3);
            }
        } else {
            // Default: 3 months ago
            $startDate = Carbon::now()->subMonths(3);
        }

        return [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
    }
    /**
     * Export reports data before deletion
     */
    public function exportReports(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            $action_by = $request->input('action_by');

            try {
                $dateRange = $this->getDateRange($request);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid date format: ' . $e->getMessage()
                ], 400);
            }

            $query = Report::where('created_at', '<', $dateRange['end_date'])
                ->where('created_at', '>=', $dateRange['start_date']);

            // Add user_id filter if provided
            if ($userId) {
                $query->where('user_id', $userId);
            }

            $reports = $query->get();

            if ($reports->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No reports found within specified date range'
                ], 404);
            }

            // Prepare data for CSV export
            $filename = 'reports_export_' . now()->format('Y-m-d_His') . '.csv';
            $tempPath = storage_path('app/temp_' . $filename);
            $file = fopen($tempPath, 'w');

            // Add headers
            fputcsv($file, array_keys($reports->first()->toArray()));

            // Add rows
            foreach ($reports as $report) {
                fputcsv($file, $report->toArray());
            }

            fclose($file);

            // Create file object from temp file
            $url = $this->exportDataToCsv(
                $reports,
                'reports',
                $action_by,
                $userId,
                $dateRange,
                'reports'
            );

            // Provide file as response
            return response()->json([
                'success' => true,
                'data' => $url
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export reports: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Delete old reports data
     */
    public function deleteReports(Request $request)
    {
        try {
            $userId = $request->input('user_id');

            try {
                $dateRange = $this->getDateRange($request);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid date format: ' . $e->getMessage()
                ], 400);
            }

            $query = Report::where('created_at', '<', $dateRange['end_date'])
                ->where('created_at', '>=', $dateRange['start_date']);

            // Add user_id filter if provided
            if ($userId) {
                $query->where('user_id', $userId);
            }

            $count = $query->count();

            if ($count === 0) {
                return response()->json([
                    'success' => true,
                    'message' => "No reports found to delete within the specified date range"
                ]);
            }

            $query->delete();

            // Store record in clean_up_records table
            $this->createCleanupRecord(
                'reports',
                $request->input('action_by'),
                'delete',
                $count,
                $userId,
                $dateRange
            );

            return response()->json([
                'success' => true,
                'message' => "$count old reports deleted successfully",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete reports: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Export out reports data before deletion
     */
    public function exportOutReports(Request $request)
    {
        try {
            $userId = $request->input('user_id');
			
            try {
                $dateRange = $this->getDateRange($request);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid date format: ' . $e->getMessage()
                ], 400);
            }

            $query = OutReport::where('created_at', '<', $dateRange['end_date'])
                ->where('created_at', '>=', $dateRange['start_date']);

            // Add user_id filter if provided
            if ($userId) {
                $query->where('user_id', $userId);
            }

            $outReports = $query->get();

            if ($outReports->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No out reports found within specified date range'
                ], 404);
            }

            $url = $this->exportDataToCsv(
                $outReports,
                'out_reports',
                $request->input('action_by'),
                $userId,
                $dateRange,
                'out_reports'
            );
            // Provide file as response
            return response()->json([
                'success' => true,
                'data' => $url
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export out reports: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Delete old out reports data
     */
    public function deleteOutReports(Request $request)
    {
        try {
            $userId = $request->input('user_id');

            try {
                $dateRange = $this->getDateRange($request);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid date format: ' . $e->getMessage()
                ], 400);
            }

            $query = OutReport::where('created_at', '<', $dateRange['end_date'])
                ->where('created_at', '>=', $dateRange['start_date']);

            // Add user_id filter if provided
            if ($userId) {
                $query->where('user_id', $userId);
            }

            $count = $query->count();

            if ($count === 0) {
                return response()->json([
                    'success' => true,
                    'message' => "No out reports found to delete within the specified date range"
                ]);
            }

            $query->delete();

            // Store record in clean_up_records table
            $this->createCleanupRecord(
                'out_reports',
                $request->input('action_by'),
                'delete',
                $count,
                $userId,
                $dateRange
            );

            return response()->json([
                'success' => true,
                'message' => "$count old out reports deleted successfully",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete out reports: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Export campaigns data before deletion
     */
    public function exportCampaigns(Request $request)
    {
        try {
            $userId = $request->input('user_id');

            try {
                $dateRange = $this->getDateRange($request);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid date format: ' . $e->getMessage()
                ], 400);
            }

            $query = Campaign::where('created_at', '<', $dateRange['end_date'])
                ->where('created_at', '>=', $dateRange['start_date']);

            // Add user_id filter if provided
            if ($userId) {
                $query->where('user_id', $userId);
            }

            $campaigns = $query->get();

            if ($campaigns->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No campaigns found within specified date range'
                ], 404);
            }

            // Prepare data for CSV export
            $url = $this->exportDataToCsv(
                $campaigns,
                'campaigns',
                $request->input('action_by'),
                $userId,
                $dateRange,
                'campaigns'
            );

            // Provide file as response
            return response()->json([
                'success' => true,
                'data' => $url
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export campaigns: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Export campaign reports data before deletion
     */
    public function exportCampaignReports(Request $request)
    {
        try {
            $userId = $request->input('user_id');

            try {
                $dateRange = $this->getDateRange($request);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid date format: ' . $e->getMessage()
                ], 400);
            }

            $query = Campaign::where('created_at', '<', $dateRange['end_date'])
                ->where('created_at', '>=', $dateRange['start_date']);

            // Add user_id filter if provided
            if ($userId) {
                $query->where('user_id', $userId);
            }

            // Get campaigns with their reports using the relationship
            $campaigns = $query->with('campaignReports')->get();

            // Collect all campaign reports
            $campaignReports = collect();
            foreach ($campaigns as $campaign) {
                $campaignReports = $campaignReports->merge($campaign->campaignReports);
            }

            if ($campaignReports->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No campaign reports found within specified date range'
                ], 404);
            }

            // Prepare data for CSV export
            $url = $this->exportDataToCsv(
                $campaignReports,
                'campaign_reports',
                $request->input('action_by'),
                $userId,
                $dateRange,
                'campaign_reports'
            );

            // Provide file as response
            return response()->json([
                'success' => true,
                'data' => $url
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export campaign reports: ' . $e->getMessage()
            ], 500);
        }
    }
  public function deleteCampaigns(Request $request)
  {
    try {
        $userId = $request->input('user_id');

        try {
            $dateRange = $this->getDateRange($request);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date format: ' . $e->getMessage()
            ], 400);
        }

        $query = Campaign::where('created_at', '<', $dateRange['end_date'])
            ->where('created_at', '>=', $dateRange['start_date']);

        // Add user_id filter if provided
        if ($userId) {
            $query->where('user_id', $userId);
        }

        // Get campaigns to count related reports before deletion
        $campaigns = $query->with('campaignReports')->get();
        
        if ($campaigns->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => "No campaigns found to delete within the specified date range"
            ]);
        }

        // Count campaigns and related reports
        $campaignCount = $campaigns->count();
        $reportCount = $campaigns->sum(function($campaign) {
            return $campaign->campaignReports->count();
        });
        
        // Delete campaigns (this will cascade to related reports if foreign keys are set up properly)
        // If not using database cascades, we need to delete reports manually
        foreach ($campaigns as $campaign) {
            // First delete related reports
            $campaign->campaignReports()->delete();
            // Then delete the campaign
            $campaign->delete();
        }

        // Store record in clean_up_records table for campaigns
        $this->createCleanupRecord(
            'campaigns',
            $request->input('action_by'),
            'delete',
            $campaignCount,
            $userId,
            $dateRange
        );
        
        // Store another record for campaign reports
        if ($reportCount > 0) {
            $this->createCleanupRecord(
                'campaign_reports',
                $request->input('action_by'),
                'delete',
                $reportCount,
                $userId,
                $dateRange
            );
        }

        return response()->json([
            'success' => true,
            'message' => "$campaignCount campaigns and $reportCount related reports deleted successfully",
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to delete campaigns: ' . $e->getMessage()
        ], 500);
    }
}
    public function getCleanupRecords(Request $request)
    {
        try {
            $query = CleanUpRecord::orderBy('created_at', 'desc')->with(['actionBy', 'user'])->get();
            return response()->json([
                'success' => true,
                'data' => $query
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve cleanup records: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Download exported file
     */
    public function downloadExport($id)
    {
        try {
            $record = CleanUpRecord::findOrFail($id);

            if ($record->action !== 'export' || !$record->file_name) {
                return response()->json([
                    'success' => false,
                    'message' => 'This record does not have an associated export file'
                ], 400);
            }

            $filepath = $record->file_name;

            if (!Storage::disk('public')->exists($filepath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Export file not found'
                ], 404);
            }

            return response()->download(
                Storage::disk('public')->path($filepath),
                $record->file_name,
                ['Content-Type' => 'text/csv']
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to download export: ' . $e->getMessage()
            ], 500);
        }
    }
	public function deleteCleanupRecords(Request $request)
    {
        try {
            $recordId = $request->input('record_id');

            if (!$recordId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Record ID is required'
                ], 400);
            }

            $record = CleanUpRecord::find($recordId);

            if (!$record) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cleanup record not found'
                ], 404);
            }
            if ($record->action === 'export') {
                $filePath = str_replace(
                    'https://waba.smsforyou.biz/storage/uploads/exports/',
                    '/home/smsforyou-waba/htdocs/waba.smsforyou.biz/public/storage/uploads/exports/',
                    $record->link
                );
                if (File::exists($filePath)) {
                    File::delete($filePath);
                    $record->forceDelete();
                    return response()->json([
                        'success' => true,
                        'message' => 'Cleanup record and associated file deleted successfully'
                    ]);
                } else {
                    return response()->json(['status' => false, 'message' => 'Something Went Wrong'], 400);
                }
            } else {
                // For non-export records, just delete the record
                $record->delete();
                return response()->json([
                    'success' => true,
                    'message' => 'Cleanup record deleted successfully'
                ]);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete cleanup record: ' . $e->getMessage()
            ], 500);
        }
    }
}
