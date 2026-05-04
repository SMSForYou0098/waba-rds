<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Settings\ErrorCode;
use Illuminate\Http\Request;
class ErrorCodeController extends Controller
{
    public function index(){
        $codes = ErrorCode::all();
        return response()->json(['status'=>true, 'codes'=>$codes]);
    }
    public function store(Request $report)
    {
        try {
            // Create a new instance of the ErrorCode model
            $codes = new ErrorCode();

            // Assign values from the request to the model
            $codes->code = $report->code;
            $codes->description = $report->description;

            // Save the model to the database
            $codes->save();

            // Return success response if everything goes well
            return response()->json([
                'status' => true,
                'message' => 'Error code saved successfully.',
                'codes' => $codes
            ], 200);

        } catch (\Exception $e) {
            // Return error response in case of an exception
            return response()->json([
                'status' => false,
                'message' => 'Error saving error code.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
  
      // Update error code
    public function update(Request $request, $id)
    {
        try {
            $code = ErrorCode::findOrFail($id);
            $code->code = $request->code;
            $code->description = $request->description;
            $code->save();

            return response()->json(['status' => true, 'codes' => $code]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

}
