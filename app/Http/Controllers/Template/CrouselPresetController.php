<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Auth\ApiKey;
use App\Models\Template\CrouselPreset;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;

class CrouselPresetController extends Controller
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client();
    }
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
  
    public function quickSend(Request $request)
    {
        $params = $request->only(['to', 'apikey', 'preset']);

        if (count($params) !== 3) {
            return response()->json(['status' => false, 'error' => 'Invalid parameter(s)', 'invalid_params' => array_diff(array_keys($request->query()), ['to', 'apikey', 'preset'])], 400);
        }

        ['to' => $to, 'apikey' => $apikey, 'preset' => $preset] = $params;

        if (!is_numeric($to) || strlen($to) < 10) {
            return response()->json(['error code' => 'SF2', 'status' => false, 'error' => 'Invalid Mobile number'], 401);
        }

        $user = ApiKey::where('status', 'true')
            ->where('key', $apikey)
            ->with('user.userConfig')
            ->first();

        if (!$user) {
            return response()->json(['error code' => 'SF0', 'status' => false, 'error' => 'Invalid API key'], 401);
        }

        if ($user->user->credit_expired === 'true') {
            return response()->json(['error code' => 'SF1', 'status' => false, 'error' => 'Insufficient Credits to send a message. Please recharge your account to use our api smoothly. Thank You'], 401);
        }

        $payLoad = CrouselPreset::where('name', $preset)->value('object');
        if (!$payLoad) {
            return response()->json(['status' => false, 'error' => 'Preset not found'], 404);
        }

        $object = str_replace(':number:', $to, $payLoad);
        $whatapp_phone_id = $user->user->userConfig->whatsapp_phone_id;
        $waToken = $user->user->userConfig->meta_access_token;
        $messageSendApi = env('WA_API_MESSAGES');
        $messagesApi = str_replace(':whatsapp_phone_id:', $whatapp_phone_id, $messageSendApi);

        try {
            $response = $this->client->post($messagesApi, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => "Bearer {$waToken}",
                ],
                'json' => json_decode($object, true),
            ]);

            $responseBody = json_decode($response->getBody()->getContents());

            if (isset($responseBody->messages[0]->id)) {
                return response()->json([
                    'status' => true,
                    'message_id' => encrypt($responseBody->messages[0]->id),
                    'message' => 'Message submitted successfully'
                ], 200);
            }

            return response()->json(['success' => $responseBody]);
        } catch (GuzzleException | Exception $e) {
            return response()->json(['error' => 'Something Went Wrong', 'errorMessage' => $e->getMessage()], 500);
        }
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
