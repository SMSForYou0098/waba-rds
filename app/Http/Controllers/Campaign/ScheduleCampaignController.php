<?php

namespace App\Http\Controllers\Campaign;

use App\Http\Controllers\Controller;
use App\Models\Campaign\ScheduleCampaign;
use App\Models\Campaign\ScheduleCampaignReport;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Cache;

class ScheduleCampaignController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($id)
    {
        $history = ScheduleCampaign::where('user_id', $id)->get();
        return response()->json(['campaign' => $history]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $ip = file_get_contents('https://api.ipify.org');
        $campaign = new ScheduleCampaign();
        $campaign->user_id = $request->user_id;
        $campaign->name = $request->name;
        $campaign->campaign_type = $request->campaign_type;
        $campaign->custom_text = $request->custom_text;
        $campaign->template_name = $request->template_name;
        $campaign->header_type = $request->header_type;
        $campaign->button_type = $request->button_type;
        $campaign->header_media_url = $request->header_media_url;
        // a
        $campaign->numbers = implode(',', $request->numbers);
        $campaign->body_values = implode(',', $request->body_values);
        $campaign->button_value = implode(',', $request->button_value);
        // $campaign->template_body_object = $request->template_body_object;
        $campaign->schedule_date = date('Y-m-d', strtotime($request->schedule_date));
        $campaign->schedule_time = $request->schedule_time;
        $campaign->status = 'pending';
        $campaign->ip = $ip;
        $campaign->save();

        if (isset($campaign)) {
           $this->UpdateConfig($campaign);
        }

        return response()->json([
            'status' => true,
            'message' => 'Campaign schedule successfully'
        ], 200);
    }

    public function reschedule(Request $request, $id)
    {
        $ip = file_get_contents('https://api.ipify.org');
        $campaign = ScheduleCampaign::findOrFail($request->id);
        $campaign->schedule_date = date('Y-m-d', strtotime($request->schedule_date));
        $campaign->schedule_time = $request->schedule_time;
        $campaign->status = 'pending';
        $campaign->ip = $ip;
        $campaign->save();

        if (isset($campaign)) {
            $this->UpdateConfig($campaign);
         }

        return response()->json([
            'status' => true,
            'message' => 'Campaign reschedule successfully'
        ], 200);
    }

    public function handleStatus(Request $request, $id){
        $campaign = ScheduleCampaign::findOrFail($id);
        //return response()->json(['status' => true, 'message' => $campaign],200);
        $campaign->status = $request->status;
        $campaign->save();
        if (isset($campaign)) {
            $this->UpdateConfig($campaign);
         }
        return response()->json(['status' => true, 'message' => 'Campaign Updated successfully'],200);
    }
    public function CampaignReport(Request $request)
    {
        try {
            //store campaign data by user
            $campaign = new ScheduleCampaignReport();
            $campaign->campaign_id = $request->campaign_id;
            $campaign->message_id = $request->message_id;
            $campaign->mobile_number = $request->mobile_number;
            $campaign->status = 'sent';
            $campaign->save();
            if (isset($campaign)) {
                $this->UpdateConfig($campaign);
             }
            return response()->json(['status' => true, 'message' => 'Campaign stored successfully', 'campaign' => $campaign]);
        } catch (QueryException $exception) {
            // Handle database query exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        } catch (\Exception $exception) {
            // Handle other exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        }
    }
    public function CampaignReportData($id)
    {
        // Assuming you have a campaign ID
        $campaign = ScheduleCampaignReport::where('campaign_id', $id)->get();
        return response()->json(['campaign' => $campaign]);
    }
    public function show(string $id)
    {
        //
    }


    public function edit(string $id)
    {
        //
    }

    public function update(Request $request, string $id)
    {
        //
    }

    public function destroy(string $id)
    {
        $campaign = ScheduleCampaign::findOrFail($id);
        $campaign->delete();
        if (isset($campaign)) {
            $this->UpdateConfig($campaign);
         }
        return response()->json(['status' => true, 'message' => 'Campaign deleted successfully']);
    }
    private function UpdateConfig($campaign){
        if (isset($campaign)) {
            Cache::forget('todays_data');
            $currentDate = now()->setTimezone('Asia/Kolkata')->toDateString();
            $data = ScheduleCampaign::where('status', 'pending')
                ->whereDate('schedule_date', $currentDate)
                ->with([
                    'user.apiKey' => function ($query) {
                        $query->where('status', 'true');
                    }
                ])->get();
            Cache::put('todays_data', $data);
        }
    }
}
