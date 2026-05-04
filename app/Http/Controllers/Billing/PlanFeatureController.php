<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Billing\PlanFeature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PlanFeatureController extends Controller
{
    /**
     * Display a listing of plan features
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Use with() to eager load the plans with each feature
        $features = PlanFeature::all()->map(function ($feature) {
            // Manually attach the plans to each feature
            $feature->related_plans = $feature->plans();
            return $feature;
        });

        return response()->json([
            'success' => true,
            'data' => $features
        ]);
    }

    /**
     * Store a newly created plan feature
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'features' => 'nullable|json',
            'active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $planFeature = PlanFeature::create($request->all());

        return response()->json([
            'success' => true,
            'data' => $planFeature
        ], 201);
    }

    /**
     * Display the specified plan feature
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $planFeature = PlanFeature::find($id);

        if (!$planFeature) {
            return response()->json([
                'success' => false,
                'message' => 'Plan feature not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $planFeature
        ]);
    }

    /**
     * Update the specified plan feature
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $planFeature = PlanFeature::find($id);

        if (!$planFeature) {
            return response()->json([
                'success' => false,
                'message' => 'Plan feature not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'features' => 'nullable|json',
            'active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $planFeature->update($request->all());

        return response()->json([
            'success' => true,
            'data' => $planFeature
        ]);
    }

    /**
     * Remove the specified plan feature
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $planFeature = PlanFeature::find($id);

        if (!$planFeature) {
            return response()->json([
                'success' => false,
                'message' => 'Plan feature not found'
            ], 404);
        }

        $planFeature->delete();

        return response()->json([
            'success' => true,
            'message' => 'Plan feature deleted successfully'
        ]);
    }

    /**
     * Get all plans that include this feature
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function getPlans($id)
    {
        $planFeature = PlanFeature::find($id);

        if (!$planFeature) {
            return response()->json([
                'success' => false,
                'message' => 'Plan feature not found'
            ], 404);
        }

        $plans = $planFeature->plans();

        return response()->json([
            'success' => true,
            'data' => $plans
        ]);
    }
}
