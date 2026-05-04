<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Billing\PlanFeature;

class PlanController extends Controller
{
    /**
     * Display a listing of all plans
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
       $plans = Plan::all();
       $plans->each(function ($plan) {
            $featureIds = $plan->features;
            if (is_string($featureIds)) {
                // Try to decode if it's a JSON string
                $decoded = json_decode($featureIds, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $featureIds = $decoded;
                }
            }
            $plan->feature_data = PlanFeature::whereIn('id', $featureIds)->get();
        });
        return response()->json([
            'success' => true,
            'data' => $plans
        ]);
    }

    /**
     * Store a newly created plan
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'monthly_price' => 'nullable|numeric',
            'yearly_price' => 'nullable|numeric',
            'custom_price' => 'nullable|numeric',
          	'role_id' => 'required|exists:roles,id',
            'button_text' => 'required|string|max:255',
            'features' => 'nullable|required|array',
            'recommended' => 'boolean',
            'active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
		$data = $request->all();	
    	$data['features'] = json_encode($data['features'] ?? []);
        $plan = Plan::create($data);

        return response()->json([
            'success' => true,
            'data' => $plan
        ], 201);
    }

    /**
     * Display the specified plan
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $plan = Plan::find($id);

        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $plan
        ]);
    }

    /**
     * Update the specified plan
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $plan = Plan::find($id);

        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'monthly_price' => 'nullable|numeric',
            'yearly_price' => 'nullable|numeric',
            'custom_price' => 'nullable|numeric',
          	'role_id' => 'required|exists:roles,id',
            'button_text' => 'required|string|max:255',
            'features' => 'nullable|required|array',
            'recommended' => 'boolean',
            'active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
		$data = $request->all();	
    	$data['features'] = json_encode($data['features'] ?? []);

        $plan->update($request->all());

        return response()->json([
            'success' => true,
            'data' => $plan
        ]);
    }

    /**
     * Remove the specified plan
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $plan = Plan::find($id);

        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not found'
            ], 404);
        }

        $plan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Plan deleted successfully'
        ]);
    }
  
  	public function storeConfig(Request $request, $planId)
    {
        // Check if plan exists
        $plan = Plan::find($planId);
        if (!$plan) {
            return response()->json([
                'status' => false,
                'message' => 'Plan not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'support_agent' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Update or create config
        $config = PlanConfig::updateOrCreate(
            ['plan_id' => $planId],
            $request->all()
        );

        return response()->json([
            'status' => true,
            'data' => $config
        ], 200);
    }

    public function getConfig($planId)
    {
        // Check if plan exists
        $plan = Plan::find($planId);
        if (!$plan) {
            return response()->json([
                'status' => false,
                'message' => 'Plan not found'
            ], 404);
        }

        $config = PlanConfig::where('plan_id', $planId)->first();

        if (!$config) {
            return response()->json([
                'status' => false,
                'message' => 'Plan configuration not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $config
        ]);
    }
  
}
