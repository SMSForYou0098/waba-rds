<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Billing\PricingModel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class PricingModelController extends Controller
{

    public function index($id)
    {
        $history = PricingModel::where('user_id', $id)
        ->get();
        return response()->json(['pricing_history' => $history]);
    }
    public function create(Request $request){
        try {
            $existingRecord = PricingModel::where('user_id', $request->user_id)->first();

            if ($existingRecord) {
                $existingRecord->price_alert = $request->priceAlert;
                $existingRecord->marketing_price = $request->marketingPrice;
                $existingRecord->utility_price = $request->utilityPrice;
                $existingRecord->service_price = $request->servicePrice;
                $existingRecord->authentication_price = $request->authenticationPrice;
                $existingRecord->save();
                return response()->json(['status' => true, 'message' => 'Pricing updated successfully']);
            } else {
                // Create a new record
                $newRecord = new PricingModel();
                $newRecord->user_id = $request->user_id;
                $newRecord->price_alert = $request->priceAlert;
                $newRecord->marketing_price = $request->marketingPrice;
                $newRecord->utility_price = $request->utilityPrice;
                $newRecord->service_price = $request->servicePrice;
                $newRecord->authentication_price = $request->authenticationPrice;
                $newRecord->save();
                return response()->json(['status' => true, 'message' => 'Pricing created successfully']);
            }
        } catch (QueryException $exception) {
            // Handle database query exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        } catch (\Exception $exception) {
            // Handle other exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        }
    }
}
