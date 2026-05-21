<?php

namespace App\Http\Controllers\Template;

use App\Exceptions\Messaging\LegacyApiValidationException;
use App\Http\Controllers\Controller;
use App\Models\Template\CrouselPreset;
use App\Services\Template\CrouselPresetQuickSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrouselPresetController extends Controller
{
    public function __construct(
        private readonly CrouselPresetQuickSendService $quickSendService,
    ) {}
    public function index($id)
    {
        $latestPresets = CrouselPreset::where('user_id', $id)->latest()->get();
        $thresholdDate = now()->subDays(15);

        if ($latestPresets->isNotEmpty()) {
            foreach ($latestPresets as $preset) {
                if (
                    ($preset->updated_at && $preset->updated_at < $thresholdDate) ||
                    (!$preset->updated_at && $preset->created_at < $thresholdDate)
                ) {
                    $preset->expired = true;
                } else {
                    $preset->expired = false;
                }
            }

            return response()->json(['status' => true, 'preset' => $latestPresets], 200);
        } else {
            return response()->json(['status' => false, 'message' => 'No presets found.'], 404);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $preset = new CrouselPreset();
        $preset->user_id = $request->user_id;
      	$preset->category = $request->category;
        $preset->name = $request->name;
        $preset->object = json_encode($request->object);
        $preset->save();
        return response()->json(['status' => true], 200);
    }
	
  	public function update(Request $request)
    {
        $preset = CrouselPreset::findOrFail($request->id);
        $preset->name = $request->name;
        $preset->object = json_encode($request->object);
        $preset->save();
        return response()->json(['status' => true], 200);
    }
  
    public function quickSend(Request $request): JsonResponse
    {
        try {
            $result = $this->quickSendService->execute($request);
        } catch (LegacyApiValidationException $e) {
            return response()->json([
                'error code' => $e->errorCode,
                'status' => false,
                'error' => $e->getMessage(),
            ], $e->httpStatus);
        }

        $status = $result['http_status'];
        unset($result['http_status'], $result['ok']);

        if (isset($result['error_code'])) {
            $result['error code'] = $result['error_code'];
            unset($result['error_code']);
        }

        return response()->json($result, $status);
    }
  
 	public function destroy($id)
    {
        // Find the preset by ID
        $preset = CrouselPreset::find($id);

        // Check if preset exists
        if ($preset) {
            // Delete the preset
            $preset->delete();

            // Return success response
            return response()->json(['status' => true, 'message' => 'Preset deleted successfully.'], 200);
        } else {
            // Return error response if preset not found
            return response()->json(['status' => false, 'message' => 'Preset not found.'], 404);
        }
    }
}
