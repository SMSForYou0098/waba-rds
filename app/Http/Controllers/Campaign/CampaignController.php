<?php

namespace App\Http\Controllers\Campaign;

use App\Http\Controllers\Controller;
use App\Models\Campaign\Campaign;
use App\Models\Campaign\CampaignReport;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CampaignController extends Controller
{
    public function index(Request $request, $id)
    {
        // Start with a base query
        $query = Campaign::where('user_id', $id);
        // Apply date range filter if provided
        if ($request->filled(['startDate', 'endDate'])) {
            $startDate = Carbon::createFromFormat('d/m/y', $request->startDate)->startOfDay();
            $endDate = Carbon::createFromFormat('d/m/y', $request->endDate)->endOfDay();
        } else {
            $startDate = Carbon::today()->startOfDay();
            $endDate = Carbon::today()->endOfDay();
        }
        $query->whereBetween('created_at', [$startDate, $endDate]);
        // Apply search filter if provided
        if ($request->filled('search')) {
            $search = strtolower($request->search);
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(template_name) LIKE ?', ["%{$search}%"]);
            });
        }
        // Fetch campaigns
        $campaigns = $query->get(['id', 'user_id', 'name', 'template_name', 'created_at']);
        // Fetch only the counts needed for effectiveness ratio
        $reportStats = DB::table('campaign_reports')
            ->select(
                'campaign_id',
                DB::raw('COUNT(*) as total_count'),
                DB::raw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count"),
                DB::raw("SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count"),
                DB::raw("SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count"),
                DB::raw("SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_count"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count"),
                DB::raw("SUM(CASE WHEN billable = '1' THEN 1 ELSE 0 END) as billable_count")
            )
            ->whereIn('campaign_id', $campaigns->pluck('id'))
            ->groupBy('campaign_id')
            ->get()
            ->keyBy('campaign_id');

        // Attach only effectiveness ratio to campaigns
        $campaigns->transform(function ($campaign) use ($reportStats) {
            if (isset($reportStats[$campaign->id])) {
                $stats = $reportStats[$campaign->id];
                $validReports = $stats->total_count;
                $campaign->billable_count = $stats->billable_count;
                $campaign->campaign_reports_count = $stats->total_count;
                //$campaign->validReports = $validReports;
                // Calculate delivery ratio
                $totalDelivered = $stats->delivered_count + $stats->read_count;
                $campaign->sent_count = $stats->sent_count;
                $campaign->delivered_count = $stats->delivered_count;
                $campaign->read_count = $stats->read_count;
                $campaign->failed_count = $stats->failed_count;
                $campaign->pending_count = $stats->pending_count;
                // Calculate sent ratio
                $campaign->sent_ratio = ($campaign->sent_count / $validReports) * 100;
                $processedCount = (int) $campaign->sent_count + (int) $campaign->failed_count;
                $campaign->progress_percent = $validReports > 0
                    ? round(($processedCount / $validReports) * 100, 2)
                    : 0.0;
                $campaign->campaign_status = ((int) $campaign->pending_count) === 0 ? 'completed' : 'processing';
                $campaign->should_subscribe_reverb = ((int) $campaign->pending_count) > 0;
                $campaign->progress_channel = 'campaign.'.(int) $campaign->user_id;

                $validNumerator = (int) $campaign->sent_count +
                    (int) $campaign->delivered_count +
                    (int) $campaign->read_count +
                    (int) $campaign->failed_count +
                    (int) $campaign->pending_count;

                $validDenominator = (int) $campaign->campaign_reports_count;

                if ($validDenominator > 0 && $validNumerator === $validDenominator) {
                    $campaign->valid_total = '100%';
                } else {
                    $campaign->valid_total = $validNumerator . '/' . $validDenominator;
                }
                $campaign->sent_report = $stats->sent_count . '/' . $campaign->campaign_reports_count;

                $campaign->delivery_ratio = $validReports > 0
                    ? round(($totalDelivered / $validReports) * 100, 2)
                    : 0;

                // Calculate read ratio
                $campaign->read_ratio = $validReports > 0
                    ? round(($stats->read_count / $validReports) * 100, 2)
                    : 0;
            } else {
                $campaign->campaign_reports_count = 0;
                $campaign->sent_count = 0;
                $campaign->delivered_count = 0;
                $campaign->read_count = 0;
                $campaign->failed_count = 0;
                $campaign->pending_count = 0;
                $campaign->sent_ratio = 0;
                $campaign->progress_percent = 0.0;
                $campaign->campaign_status = 'not_started';
                $campaign->should_subscribe_reverb = false;
                $campaign->progress_channel = 'campaign.'.(int) $campaign->user_id;
                $campaign->delivery_ratio = 0;
                $campaign->read_ratio = 0;
            }

            return $campaign;
        });

        return response()->json(['campaign' => $campaigns], 200);
    }
  
    public function create(Request $request)
    {
        try {

            // Create a new Campaign
            $newRecord = new Campaign();
            $newRecord->name = $request->name;
            $newRecord->user_id = $request->user_id;
            $newRecord->template_name = $request->templateName;
            $newRecord->save();

            return response()->json(['status' => true, 'message' => 'Campaign stored successfully', 'campaign' => $newRecord]);
        } catch (QueryException $exception) {
            // Handle database query exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        } catch (\Exception $exception) {
            // Handle other exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        }
    }
    public function CampaignReport(Request $request)
    {
        try {
            $reports = $request->reports; // Array of report data

            foreach ($reports as $report) {
                $campaign = new CampaignReport();
                $campaign->campaign_id = $report['campaign_id'] ;
                $campaign->message_id = $report['message_id'] ?? NULL;
                $campaign->mobile_number = $report['mobile_number'];
              	$campaign->template_category = $request->template_category;
                $campaign->status = 'pending';
                $campaign->save();
            }

            return response()->json(['status' => true, 'message' => 'Campaign reports stored successfully']);
        } catch (QueryException $exception) {
            // Handle database query exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        } catch (\Exception $exception) {
            // Handle other exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        }
    }

    // protected function MakeOutReport(Request $request)
    // {
    //     $out_report = new OutReport();
    //     $out_report->display_phone_number = $request->display_phone_number;
    //     $out_report->recipient_id = $request->waId;
    //     $out_report->status_id = $request->message_id;
    //     $out_report->save();
    //     return response()->json(['response' => $out_report], 200);
    // }
    public function CampaignReportData($id)
    {
        $campaign = CampaignReport::with(['errorCode']) // Use the defined relationship
            ->where('campaign_id', $id)
            ->orderBy('updated_at', 'desc')
            ->get();

        // Transforming the campaign data to include error message
        $campaignData = $campaign->map(function ($item) {
            return [
                'id' => $item->id,
                'mobile_number' => $item->mobile_number,
                'updated_at' => $item->updated_at,
                'status' => $item->status,
                'billable' => $item->billable,
                'error_message' => $item?->error_code ? $item->errorCode?->description : null, // Get error description

            ];
        });

        return response()->json(['campaign' => $campaignData]);
    }
  
  	public function updateCampaignReport(Request $request)
    {
        $request->validate([
            'campaign_id' => 'required|integer',
            'mobile_number' => 'required|string',
            'status' => 'required|string',
            'message_id' => 'nullable|string',
        ]);
      
        $updated = CampaignReport::where('campaign_id', $request->campaign_id)
            ->where('mobile_number', $request->mobile_number)
            ->update([
                'status' => $request->status,
                'message_id' => $request->message_id,
                'updated_at' => now(),
            ]);

        if ($updated) {
            return response()->json(['status' => true, 'message' => 'Campaign report updated']);
        } else {
            return response()->json(['status' => false, 'message' => 'Report not found'], 404);
        }
    }
  
    public function BulkUpdateCampaignReport(Request $request)
    {
        try {
            $reports = $request->reports; // Array of report data

            if (empty($reports)) {
                return response()->json(['status' => false, 'message' => 'No reports provided'], 400);
            }

            // Build the CASE WHEN SQL for status and message_id
            $ids = [];
            $casesStatus = '';
            $casesMessageId = '';
            foreach ($reports as $report) {
                $cid = (int)$report['campaign_id'];
                $mno = addslashes($report['mobile_number']);
                $status = addslashes($report['status']);
                $msgid = isset($report['message_id']) ? addslashes($report['message_id']) : null;
                $ids[] = "('$cid','$mno')";
                $casesStatus .= "WHEN campaign_id = $cid AND mobile_number = '$mno' THEN '$status' ";
                $casesMessageId .= "WHEN campaign_id = $cid AND mobile_number = '$mno' THEN " . ($msgid ? "'$msgid'" : "NULL") . " ";
            }

            $idsList = implode(',', $ids);

            $sql = "
                UPDATE campaign_reports
                SET 
                    status = CASE $casesStatus ELSE status END,
                    message_id = CASE $casesMessageId ELSE message_id END,
                    updated_at = NOW()
                WHERE (campaign_id, mobile_number) IN ($idsList)
            ";

            \DB::statement($sql);
            return response()->json(['status' => true, 'message' => 'Campaign reports updated at light speed!']);
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        }
    }

    public function FlowData(Request $request)
    {
        // Log the data
        \Log::info('Flow data processed', $request->all());
    }
}
