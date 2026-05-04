<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Auth\ApiKey;
use App\Models\Report\OutReport;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;

class SendMessageByObject extends Controller
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client();
    }
    public function sendMessage(Request $request, $whatapp_phone_id)
    {
        $params = $request->only(['apikey']);

        if (count($params) !== 1) {
            return response()->json([
                'status' => false,
                'error' => 'Invalid parameter(s)',
                'invalid_params' => array_diff(
                    array_keys($request->query()),
                    ['apikey']
                )
            ], 400);
        }

        ['apikey' => $apikey] = $params;


        $user = ApiKey::where('status', 'true')
            ->where('key', $apikey)
            ->with([
                'user.balance' => function ($query) {
                    $query->latest()->limit(1);  // Fetch only the latest balance
                },
                'user.pricingModel' => function ($query) {
                    $query->select('user_id', 'marketing_price');  // Fetch only the marketing price
                },
                'user.userConfig' => function ($query) {
                    $query->select('user_id', 'meta_access_token');  // Fetch only the meta_access_token
                }
            ])
            ->first();

        $latest_balance = $user->user->balance[0]->total_credits ?? null;
        $marketing_price = $user->user->pricingModel->marketing_price ?? 0;

        if (!$user) {
            return response()->json(['error code' => 'SF0', 'status' => false, 'error' => 'Invalid API key'], 401);
        }
        if ($latest_balance < $marketing_price) {
            return response()->json(['error code' => 'SF3', 'status' => false, 'error' => 'Insufficient Credits to send a message. Please recharge your account to use our api smoothly. Thank You'], 401);
        }
        $object = $request->data;
        $to = $object['to'];
        $waToken = $user->user->userConfig->meta_access_token;
        $messageSendApi = env('WA_API_MESSAGES');
        //return response()->json(['error code' => 'SF0', 'status' => $messageSendApi], 401);
        $messagesApi = str_replace(':whatsapp_phone_id:', $whatapp_phone_id, $messageSendApi);
        try {
            $response = $this->client->post($messagesApi, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $waToken,
                ],
                'body' => json_encode($object),
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            $response = json_decode($responseBody);
            if ($response->messages[0]->id) {
                $this->MakeOutReport($to, $user->user->whatsapp_number, $response->messages[0]->id, $user->user->id);
                return response()->json([
                    'status' => true,
                    'message_id' => encrypt($response->messages[0]->id),
                    'message' => 'Message submitted successfully'
                ], 200);
            }
            if ($statusCode >= 200 && $statusCode < 300) {
                return response()->json(['success' => $responseBody]);
            } else {
                $errorMessage = isset($response->error->message) ? $response->error->message : 'Something Went Wrong';
                $errorCode = isset($response->error->code) ? $response->error->code : $statusCode;

                return response()->json([
                    'error' => [
                        'message' => $errorMessage,
                        'code' => $errorCode,
                        'response_body' => $responseBody, // Optionally include the full response body
                    ]
                ], 500);
            }
        } catch (GuzzleException $e) {
            return response()->json(['error2' => 'Something Went Wrong', 'errorMessage' => $e->getMessage()], 500);
        } catch (Exception $e) {
            return response()->json(['error3' => 'Something Went Wrong', 'errorMessage' => $e->getMessage()], 500);
        }
    }

    protected function MakeOutReport($to, $whatapp_number, $messageID, $user_id)
    {
        $out_report = new OutReport();
        $out_report->user_id = $user_id;
        $out_report->display_phone_number = $whatapp_number;
        $out_report->status_id = $messageID;
        $out_report->recipient_id = $to;
        $out_report->save();
        return response()->json(['response' => $out_report], 200);
    }
}
