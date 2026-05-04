<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Events\ReportUpdated;
use App\Jobs\AssignChatToSupportAgent;
use App\Models\Billing\Balance;
use App\Models\Campaign\CampaignReport;
use App\Models\Chat\Chatbot;
use App\Models\Report\Logdata;
use App\Models\Report\OutReport;
use App\Models\Report\Report;
use App\Models\Campaign\ScheduleCampaignReport;
use App\Models\Contact\ServerBlockNumber;
use App\Models\Settings\Setting;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Log;
use PHPUnit\Framework\Attributes\BackupGlobals;
use Carbon\Carbon;


class WebhookController extends Controller
{
    protected $formattedTime;

    // Constructor to initialize the formatted time
    public function __construct()
    {
        $this->formattedTime = Carbon::now()->format('Y-m-d h:i:s A');
    }
    public function handle(Request $request)
    {

        $mode = $request->hub_mode;
        $challenge = $request->hub_challenge;
        $token = $request->hub_verify_token;

        if ($mode == 'subscribe' && $token == 'smitbhagat91') {
            return response($challenge, 200);
        } else {
            return response('Verification failed', 400);
        }
    }

    public function Webhook(Request $request)
    {
        $data = $request->all();
        $isLogEnable = Setting::where('id', 1)->value('logs');

        // Log incoming data if logging is enabled
        if ($data && $isLogEnable === 'true') {
            Logdata::create(['logs' => serialize($data)]);
        }
        if (!isset($data['entry'][0]['changes'][0]['value']['statuses'])) {
            $this->HandleReadMessage($data);

            $entry = $data['entry'][0];
            $reportData = $entry['changes'][0]['value'];
            $messages = $reportData['messages'][0];

            // Handle button or normal message
            $incomingMessage = strtolower($messages['button']['text'] ?? $messages['text']['body']);
	
            // Store report data in the database
            $report = $this->saveReportData($data, $entry, $reportData, $messages);
			AssignChatToSupportAgent::dispatch($user, $report)
                    ->onConnection('assign_chat')
                    ->onQueue('assign_chat');
            // Process the incoming message
            if (!empty($incomingMessage) && $report->wa_id) {
                $userBlocked = $this->getBlockedNumbers($report->display_phone_number);
                if (!in_array($report->wa_id, $userBlocked)) {
                    return $this->prepareChatbot($incomingMessage, $report, $isLogEnable);
                }
            }
        } else {
            // Handle status updates
            return $this->processStatusUpdates($data);
        }
    }
	private function checkExistingReport(array $reportData, array $messages): ?Report
    {
        return Report::where('display_phone_number', $reportData['metadata']['display_phone_number'])
            ->where('timestamp', $messages['timestamp'])
            ->where('text_body', $messages['text']['body'] ?? $messages['button']['text'] ?? null)
            ->where('wa_id', $reportData['contacts'][0]['wa_id'])
            ->first();
    }
    //save report (incoming message)
    private function saveReportData($data, $entry, $reportData, $messages)
    {
        $existingReport = $this->checkExistingReport($reportData, $messages);
        if ($existingReport) {
            //return $existingReport;
            return response()->json([
                'message' => 'Report already exists',
                'status' => 'duplicate'
            ], 409);
        }
        $report = new Report();
        $report->object = $data['object'];
        $report->report_id = $entry['id'];
        $report->messaging_product = $reportData['messaging_product'];
        $report->display_phone_number = $reportData['metadata']['display_phone_number'];
        $report->phone_number_id = $reportData['metadata']['phone_number_id'];
        $report->profile_name = $reportData['contacts'][0]['profile']['name'];
        $report->wa_id = $reportData['contacts'][0]['wa_id'];
        $report->messages_id = $messages['id'];
        $report->messages_type = $messages['type'];
        $report->timestamp = $messages['timestamp'];
        $report->field = $entry['changes'][0]['field'];
        $report->text_body = $messages['text']['body'] ?? $messages['button']['text'] ?? null;
        $report->save();
        return $report;
    }

    private function getBlockedNumbers($phoneNumber)
    {
        $userBlockedNumbers = User::where('whatsapp_number', $phoneNumber)
            ->where('credit_expired', 'false')
            ->with([
                'blockedNumbers' => function ($query) {
                    $query->where('chatbot_access', 0);
                }
            ])
            ->get()
            ->pluck('blockedNumbers.*.numbers')
            ->flatten();

        $serverBlockedNumbers = ServerBlockNumber::pluck('numbers');

        return $userBlockedNumbers->merge($serverBlockedNumbers)
            ->unique()
            ->map(function ($number) {
                return strlen($number) === 10 ? "91$number" : $number;
            })
            ->toArray();
    }


    private function processStatusUpdates($data)
    {
        $reportData = $data['entry'][0]['changes'][0]['value'];
        $status = $reportData['statuses'][0]['status'];
        $id = $reportData['statuses'][0]['id'];
        $conversation = $reportData['statuses'][0]['conversation']['id'] ?? null;
        $expireTime = $reportData['statuses'][0]['conversation']['expiration_timestamp'] ?? null;
        $outReport = OutReport::lockForUpdate()->where('status_id', $id)->first();
        $isBillable = false;
        if ($status == 'sent') {
            $isBillable = $this->handleConversation($data, $reportData, $outReport);
        }
        $this->updateCampaignStatus($id, $status, $conversation, $expireTime, $isBillable);
        if ($outReport) {
            $this->updateOutReport($outReport, $data, $status, $isBillable);
        }
    }

    private function updateCampaignStatus($messageId, $status, $conversationId, $expireTime, $isBillable)
    {
        $campaignReport = CampaignReport::lockForUpdate()->where('message_id', $messageId)->first();
        $scheduleCampaignReport = ScheduleCampaignReport::lockForUpdate()->where('message_id', $messageId)->first();

        if ($campaignReport) {
            $campaignReport->status = $status;
            $campaignReport->conversation_id = $conversationId ?? $campaignReport->conversation_id;
            $campaignReport->expiration_timestamp = $expireTime ?? $campaignReport->expiration_timestamp;
            if ($campaignReport->billable != 1) {
                $campaignReport->billable = $isBillable ? 1 : 0;
            }

            $campaignReport->save();
        }

        if ($scheduleCampaignReport) {
            $scheduleCampaignReport->status = $status;
            $scheduleCampaignReport->conversation_id = $conversationId ?? $scheduleCampaignReport->conversation_id;
            $scheduleCampaignReport->save();
        }
    }

    private function updateOutReport($outReport, $data, $status, $isBillable)
    {
        $reportData = $data['entry'][0]['changes'][0]['value'];

        if ($outReport->status != 'read') {
            $outReport->status = $status;
            $outReport->delivered_time = $status === 'delivered' ? now() : $outReport->delivered_time;
            $outReport->read_time = $status === 'read' ? now() : $outReport->read_time;
            $outReport->timestamp = $reportData['statuses'][0]['timestamp'];
            if ($outReport->billable != 1) {
                $outReport->billable = $isBillable ? 1 : 0;
            }
            $outReport->recipient_id = $reportData['statuses'][0]['recipient_id'];

            if (isset($reportData['statuses'][0]['conversation'])) {
                $conversation = $reportData['statuses'][0]['conversation'];
                $outReport->conversation_id = $conversation['id'];
                $outReport->billable = $reportData['statuses'][0]['pricing']['billable'];
                $outReport->pricing_model = $reportData['statuses'][0]['pricing']['pricing_model'];
                $outReport->category = $reportData['statuses'][0]['pricing']['category'];
                $outReport->expiration_timestamp = $conversation['expiration_timestamp'] ?? $outReport->expiration_timestamp;
            }

            $outReport->save();
        }
    }

    private function handleConversation($data, $reportData, $outReport)
    {

        $user = User::where('whatsapp_number', $reportData['metadata']['display_phone_number'])
            ->with(['balance', 'pricingModel'])
            ->lockForUpdate()
            ->first();

        if ($user) {
            $conversation = $reportData['statuses'][0]['conversation'];
            $originType = $conversation['origin']['type'] . '_price';
            $recipientId = $reportData['statuses'][0]['recipient_id'];
            $price = $user->pricingModel->$originType ?? 0;
            $conversationId = $conversation['id'];
            $displayPhoneNumber = $reportData['metadata']['display_phone_number'];

            $existingReport = $this->getExistingReport($displayPhoneNumber, $conversationId, $recipientId);
            //return response()->json(['success b' => $existingReport]);
            if (!$existingReport) {

                $balance = $user->balance()->latest()->first()->total_credits ?? 0;
                $newBalance = $balance - $price;

                if ($newBalance >= 0) {
                    $this->deductBalance($user, $price, $newBalance);
                    return true;
                } else {
                    //Log::warning("Insufficient balance for user {$user->id}");
                    // Handle insufficient balance scenario
                    return false;
                }
            }
        }
    }

    private function getExistingReport($displayPhoneNumber, $conversationId, $recipientId)
    {
        return OutReport::where('display_phone_number', $displayPhoneNumber)
            ->where('conversation_id', $conversationId)
            ->first() ??
            CampaignReport::where('mobile_number', $recipientId)
                ->where('conversation_id', $conversationId)
                ->first();
    }

    private function deductBalance($user, $price, $newBalance)
    {
        $balance = new Balance();
        $balance->user_id = $user->id;
        $balance->new_credit = $price;
        $balance->total_credits = $newBalance;
        $balance->payment_type = 'cash';
        $balance->account_manager_id = $user->reporting_user;
        $balance->auto_deduction = 'true';
        $balance->save();

    }
    protected function prepareChatbot($incomingMessage, $report, $isLogEnable)
    {
        if (!empty($incomingMessage)) {
            $otp = '';
            $AccountNumber = '';
            $selectedOption = '';
            $chekNumber = '';

            if (stripos($incomingMessage, 'chq') !== false) {
                preg_match('/\b\d+\b/', $incomingMessage, $matches);
                if (!empty($matches)) {
                    $incomingMessage = 'mns_cheque no';
                    $chekNumber = $matches[0];
                }
            } else if (stripos($incomingMessage, 'csp') !== false) {
                preg_match('/\b\d+\b/', $incomingMessage, $matches);
                if (!empty($matches)) {
                    $incomingMessage = 'mns_csp_cheque no';
                    $chekNumber = $matches[0];
                }
            } else if (stripos($incomingMessage, 'pdf') !== false) {
                preg_match('/\b\d+\b/', $incomingMessage, $matches);
                if (!empty($matches)) {
                    $incomingMessage = 'mns_pdf_account no';
                    $AccountNumber = $matches[0];
                }
            }
            // return response()->json(['numberss'=> $chekNUmber], 200);
            $message = preg_match('/^\d+$/', $incomingMessage);
            if ($message == 1 && (strlen($incomingMessage) == 1)) {
                $selectedOption = $incomingMessage;
                $incomingMessage = 'mns_option no';
            } else if ($message == 1 && (strlen($incomingMessage) == 6)) {
                $otp = $incomingMessage;
                $incomingMessage = 'mns_verify otp';
            } else if ($message == 1 && (strlen($incomingMessage) > 6)) {
                $AccountNumber = $incomingMessage;
                $incomingMessage = 'mns_account no';
            }
            try {
                $userData = User::where('whatsapp_number', $report->display_phone_number)
                    ->with([
                        'chatbots',
                        'userConfig',
                        'defaultChatbot'
                    ])
                    ->firstOrFail();
                try {
                    $url = 'https://graph.facebook.com/v20.0/' . $userData->userConfig->whatsapp_phone_id . '/messages';
                    $headers = [
                        'Authorization' => 'Bearer ' . $userData->userConfig->meta_access_token,
                        'Content-Type' => 'application/json'
                    ];

                    $matchingChatbots = $userData->chatbots->filter(function ($chatbot) use ($incomingMessage) {
                        $keywords = json_decode($chatbot->keyword, true);
                        if (!$keywords)
                            return false;
                        return in_array($incomingMessage, array_map('strtolower', $keywords));
                    });
                    if ($userData->credit_expired !== false) {
                        if (count($matchingChatbots) > 0) {
                            foreach ($matchingChatbots as $chatbot) {
                                //$count = $count + 1;
                                $chatType = $chatbot->chatbot_type;
                                $templateName = $chatbot->reply_template;
                                $Media = json_decode($chatbot->reply_template_media, true);
                                $waId = $report->wa_id;
                                // return response()->json(['Media' => $Media], 200);
                                if ($chatType == 'template') {
                                    return $this->sendTemplateMessage($waId, $templateName, $url, $headers, $report, $userData->id, !empty($Media) ? $Media : null);
                                } else {
                                    $callback_url = $chatbot->external_url;
                                    $customType = $chatbot->custom_type;
                                    $replyText = $chatbot->reply_text;
                                    $action = $chatbot->url_action_type;

                                    if ($customType === 'Url') {
                                        if (stripos($incomingMessage, 'verify otp') !== false) {
                                            $dynamic_url = str_replace(['#number', ':otp'], [$waId, $otp], $callback_url);
                                        } else if (stripos($incomingMessage, 'account no') !== false) {
                                            $dynamic_url = str_replace(['#number', ':acn'], [$waId, $AccountNumber], $callback_url);
                                        } else if (stripos($incomingMessage, 'option no') !== false) {
                                            $dynamic_url = str_replace(['#number', ':option'], [$waId, $selectedOption], $callback_url);
                                        } else if (stripos($incomingMessage, 'cheque no') !== false) {
                                            $dynamic_url = str_replace(['#number', ':chq_no'], [$waId, $chekNumber], $callback_url);
                                        } else {
                                            $dynamic_url = str_replace('#number', $waId, $callback_url);
                                        }
                                        //return response()->json(['message' => $dynamic_url], 200);
                                        $responseUrl = (new Client())->post($dynamic_url);
                                        $UrlData = json_decode($responseUrl->getBody()->getContents(), true);
                                        //return response()->json(['message' => $UrlData], 200);
                                        if ($isLogEnable == 'true') {
                                            Logdata::create(['logs' => serialize($UrlData)]);
                                        }

                                        if ($action == 'Json') {
                                            $trueKey = $chatbot->json_true_key;
                                            $trueValue = $chatbot->json_true_value;

                                            if (isset($UrlData[$trueKey]) && $UrlData[$trueKey] == $trueValue) {
                                                $jsonTrueResType = $chatbot->json_true_outgoing_res;
                                                // $incomingMessage;

                                                if ($jsonTrueResType == 'json_res') {
                                                    $messageKey = $chatbot->json_true_json_res;
                                                    $customText = $UrlData[$messageKey];
                                                    $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                                    ;
                                                } else if ($jsonTrueResType == 'Template') {
                                                    $templateName = $chatbot->json_true_template;
                                                    $Media = json_decode($chatbot->json_true_template_media, true);
                                                    $this->sendTemplateMessage($waId, $templateName, $url, $headers, $report, $userData->id, !empty($Media) ? $Media : null);
                                                } else if ($jsonTrueResType == 'Chatbot') {
                                                    $chatbotId = $messageKey = $chatbot->json_true_chatbot;
                                                    $allChatbot = $userData->chatbots;
                                                    $filterData = $allChatbot->filter(function ($item) use ($chatbotId) {
                                                        return $item->id == $chatbotId;
                                                    })->values();
                                                    $newMessage = json_decode($filterData[0]->keyword)[0];
                                                    $this->prepareChatbot2($newMessage, $report, $isLogEnable);

                                                } else if ($jsonTrueResType == 'Text') {
                                                    $customText = $chatbot->json_true_custom_text;
                                                    $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                                    ;
                                                }
                                            } else {
                                                $jsonFalseResType = $chatbot->json_false_outgoing_res;

                                                if ($jsonFalseResType == 'json_res') {
                                                    $messageKey = $chatbot->json_false_json_res;
                                                    $customText = $UrlData[$messageKey];
                                                    $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                                    ;

                                                } else if ($jsonFalseResType == 'Template') {
                                                    $templateName = $chatbot->json_false_template;
                                                    $Media = json_decode($chatbot->json_false_template_media, true);
                                                    $this->sendTemplateMessage($waId, $templateName, $url, $headers, $report, $userData->id, !empty($Media) ? $Media : null);
                                                } else if ($jsonFalseResType == 'Chatbot') {
                                                    $chatbotId = $messageKey = $chatbot->json_false_chatbot;
                                                    $allChatbot = $userData->chatbots;
                                                    $filterData = $allChatbot->filter(function ($item) use ($chatbotId) {
                                                        return $item->id == $chatbotId;
                                                    })->values();
                                                    $newMessage = json_decode($filterData[0]->keyword)[0];
                                                    $this->prepareChatbot2($newMessage, $report, $isLogEnable);

                                                } else if ($jsonFalseResType == 'Text') {
                                                    $customText = $chatbot->json_false_custom_text;
                                                    $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                                    ;
                                                }
                                            }
                                        } else if ($action == 'Text') {
                                            $customText = $chatbot->url_text;
                                            $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                            ;

                                        } else if ($action == 'XML') {
                                            // Add handling for 'XML' action if needed
                                        } else if ($action == 'Url') {
                                            $messageKey = $chatbot->url_json_key;
                                            $customText = $UrlData[$messageKey];
                                            $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                            ;
                                        }
                                    } else {
                                        $customText = $chatbot->custom_text;
                                        $customText = $replyText;
                                        $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                        ;
                                    }
                                }

                            }
                            // return response()->json(['reqs' => $count], 200);
                        } else {
                            if (isset($userData->defaultChatbot)) {
                                $defaultRes = $userData->defaultChatbot;
                                $chatType = $defaultRes->type;

                                if ($chatType === 'Custom') {
                                    $waId = $report->wa_id;
                                    $customText = $defaultRes->text;
                                    $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                    ;
                                } else {
                                    //return response()->json(['req' => 'Template Message'], 200);
                                }
                            }
                        }
                    }
                } catch (RequestException $e) {

                }


                // exit;

            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $statusCode = $response->getStatusCode();
                    $errorData = json_decode($response->getBody()->getContents(), true);
                    //return response()->json(['req' => $errorData], 200);
                }
            }
        }
    }
    protected function prepareChatbot2($incomingMessage, $report, $isLogEnable)
    {
        if (!empty($incomingMessage)) {
            $otp = '';
            $AccountNumber = '';
            $selectedOption = '';
            if (stripos($incomingMessage, 'chq') !== false) {
                preg_match('/\b\d+\b/', $incomingMessage, $matches);
                if (!empty($matches)) {
                    $incomingMessage = 'mns_cheque no';
                    $chekNumber = $matches[0];
                }
            } else if (stripos($incomingMessage, 'csp') !== false) {
                preg_match('/\b\d+\b/', $incomingMessage, $matches);
                if (!empty($matches)) {
                    $incomingMessage = 'mns_csp_cheque no';
                    $chekNumber = $matches[0];
                }
            } else if (stripos($incomingMessage, 'pdf') !== false) {
                preg_match('/\b\d+\b/', $incomingMessage, $matches);
                if (!empty($matches)) {
                    $incomingMessage = 'mns_pdf_account no';
                    $AccountNumber = $matches[0];
                }
            }
            $message = preg_match('/^\d+$/', $incomingMessage);
            if ($message == 1 && (strlen($incomingMessage) == 1)) {
                $selectedOption = $incomingMessage;
                $incomingMessage = 'mns_option no';
            } else if ($message == 1 && (strlen($incomingMessage) == 6)) {
                $otp = $incomingMessage;
                $incomingMessage = 'mns_verify otp';
            } else if ($message == 1 && (strlen($incomingMessage) > 6)) {
                $AccountNumber = $incomingMessage;
                $incomingMessage = 'mns_account no';
            }
            try {
                $userData = User::where('whatsapp_number', $report->display_phone_number)
                    ->with([
                        'chatbotAuth' => function ($query) {
                            $query->where('status', 'Active');
                        },
                        'chatbots',
                        'userConfig',
                        'defaultChatbot'
                    ])
                    ->firstOrFail();
                try {

                    $url = 'https://graph.facebook.com/v20.0/' . $userData->userConfig->whatsapp_phone_id . '/messages';
                    $headers = [
                        'Authorization' => 'Bearer ' . $userData->userConfig->meta_access_token,
                        'Content-Type' => 'application/json'
                    ];

                    $matchingChatbots = $userData->chatbots->filter(function ($chatbot) use ($incomingMessage) {
                        $keywords = json_decode($chatbot->keyword, true);
                        if (!$keywords)
                            return false;
                        return in_array(strtolower($incomingMessage), array_map('strtolower', $keywords));
                    });
                    if ($userData->credit_expired !== false) {
                        if (count($matchingChatbots) > 0) {
                            $sortedChatbots = $matchingChatbots->sortBy('sr_no')->values();

                            foreach ($sortedChatbots as $chatbot) {
                                $chatType = $chatbot->chatbot_type;
                                $templateName = $chatbot->reply_template;
                                $Media = json_decode($chatbot->reply_template_media, true);
                                $waId = $report->wa_id;
                                if ($chatType == 'template') {
                                    $this->sendTemplateMessage($waId, $templateName, $url, $headers, $report, $userData->id, !empty($Media) ? $Media : null);
                                } else {
                                    $callback_url = $chatbot->external_url;
                                    $customType = $chatbot->custom_type;
                                    $replyText = $chatbot->reply_text;
                                    $action = $chatbot->url_action_type;

                                    if ($customType === 'Url') {
                                        if (stripos($incomingMessage, 'verify otp') !== false) {
                                            $dynamic_url = str_replace(['#number', ':otp'], [$waId, $otp], $callback_url);
                                        } else if (stripos($incomingMessage, 'account no') !== false) {
                                            $dynamic_url = str_replace(['#number', ':acn'], [$waId, $AccountNumber], $callback_url);
                                        } else if (stripos($incomingMessage, 'option no') !== false) {
                                            $dynamic_url = str_replace(['#number', ':option'], [$waId, $selectedOption], $callback_url);
                                        } else if (stripos($incomingMessage, 'cheque no') !== false) {
                                            $dynamic_url = str_replace(['#number', ':chq_no'], [$waId, $chekNumber], $callback_url);
                                        } else {
                                            $dynamic_url = str_replace('#number', $waId, $callback_url);
                                        }
                                        //return response()->json(['message' => $dynamic_url], 200);
                                        $responseUrl = (new Client())->post($dynamic_url);
                                        $UrlData = json_decode($responseUrl->getBody()->getContents(), true);
                                        if ($isLogEnable == 'true') {
                                            Logdata::create(['logs' => serialize($UrlData)]);
                                        }
                                        if ($action == 'Json') {
                                            $trueKey = $chatbot->json_true_key;
                                            $trueValue = $chatbot->json_true_value;


                                            if (isset($UrlData[$trueKey]) && $UrlData[$trueKey] == $trueValue) {
                                                $jsonTrueResType = $chatbot->json_true_outgoing_res;
                                                //return response()->json(['custom req' => $jsonTrueResType], 200);

                                                if ($jsonTrueResType == 'json_res') {
                                                    $messageKey = $chatbot->json_true_json_res;
                                                    $customText = $UrlData[$messageKey];
                                                    $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                                    ;
                                                } else if ($jsonTrueResType == 'Template') {
                                                    $templateName = $chatbot->json_true_template;
                                                    $Media = json_decode($chatbot->json_true_template_media, true);
                                                    $this->sendTemplateMessage($waId, $templateName, $url, $headers, $report, $userData->id, !empty($Media) ? $Media : null);

                                                } else if ($jsonTrueResType == 'Chatbot') {
                                                    $chatbotId = $messageKey = $chatbot->json_true_chatbot;
                                                    $allChatbot = $userData->chatbots;
                                                    $filterData = $allChatbot->filter(function ($item) use ($chatbotId) {
                                                        return $item->id == $chatbotId;
                                                    })->values();
                                                    $newMessage = json_decode($filterData[0]->keyword)[0];
                                                    $this->prepareChatbot($newMessage, $report, $isLogEnable);
                                                } else if ($jsonTrueResType == 'Text') {
                                                    $customText = $chatbot->json_true_custom_text;
                                                    $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                                    ;
                                                }
                                            } else {
                                                $jsonFalseResType = $chatbot->json_false_outgoing_res;
                                                if ($jsonFalseResType == 'json_res') {
                                                    $messageKey = $chatbot->json_false_json_res;
                                                    if (stripos($incomingMessage, 'verify otp') !== false) {
                                                    }
                                                    $customText = $UrlData[$messageKey];
                                                    $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                                    ;
                                                } else if ($jsonFalseResType == 'Template') {
                                                    $templateName = $chatbot->json_false_template;
                                                    $Media = json_decode($chatbot->json_false_template_media, true);
                                                    $this->sendTemplateMessage($waId, $templateName, $url, $headers, $report, $userData->id, !empty($Media) ? $Media : null);
                                                } else if ($jsonFalseResType == 'Chatbot') {
                                                    $chatbotId = $messageKey = $chatbot->json_false_chatbot;
                                                    $allChatbot = $userData->chatbots;
                                                    $filterData = $allChatbot->filter(function ($item) use ($chatbotId) {
                                                        return $item->id == $chatbotId;
                                                    })->values();
                                                    $newMessage = json_decode($filterData[0]->keyword)[0];
                                                    $this->prepareChatbot($newMessage, $report, $isLogEnable);
                                                } else if ($jsonFalseResType == 'Text') {
                                                    $customText = $chatbot->json_false_custom_text;
                                                    $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                                    ;
                                                }
                                            }
                                        } else if ($action == 'Text') {
                                            $customText = $chatbot->url_text;
                                            $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                            ;
                                        } else if ($action == 'XML') {
                                            // Add handling for 'XML' action if needed
                                        } else if ($action == 'Url') {
                                            $messageKey = $chatbot->url_json_key;
                                            $customText = $UrlData[$messageKey];
                                            $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                            ;
                                        }
                                    } else {
                                        $customText = $chatbot->custom_text;

                                        $customText = $replyText;
                                        $this->sendTextMessage($waId, $customText, $url, $headers, $report, $userData->id);
                                        ;
                                    }
                                }

                            }

                        } else {
                            if (isset($userData->defaultChatbot)) {
                                $defaultRes = $userData->defaultChatbot;
                                $chatType = $defaultRes->type;

                                if ($chatType === 'Custom') {
                                    $this->sendTextMessage($report->wa_id, $defaultRes->type, $url, $headers, $report, $userData->id);
                                } else {
                                }
                            }
                        }
                    }
                } catch (RequestException $e) {
                }


                // exit;

            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $statusCode = $response->getStatusCode();
                    $errorData = json_decode($response->getBody()->getContents(), true);
                    //return response()->json(['req' => $errorData], 200);
                }
            }
        }
    }

    private function sendTemplateMessage($waId, $templateName, $url, $headers, $report, $user_id, $media)
    {
        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $waId,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => 'en_US'],
                'components' => [
                    ['type' => 'body', 'parameters' => []],
                    [
                        'type' => 'button',
                        'sub_type' => 'quick_reply',
                        'index' => '0',
                        'parameters' => [
                            ['type' => 'payload', 'payload' => 'reply payload']
                        ]
                    ]
                ]
            ]
        ];
        if ($media) {
            $mediaType = strtolower($media['type']);
            $mediaComponent = [
                "type" => $mediaType,
                $mediaType => [
                    "id" => $media['url'],
                ],
            ];

            // Add filename only if the media type is 'document'
            if ($mediaType === 'document') {
               // $mediaComponent[$mediaType]['filename'] = pathinfo($media['url'], PATHINFO_FILENAME);
            }

            $headerComponent = [
                "type" => "header",
                "parameters" => [$mediaComponent],
            ];

            // Insert the header at the beginning of the components array
            array_unshift($body['template']['components'], $headerComponent);
        }
        $client = new Client();
        //return $body;
        $response = $client->post($url, [
            'headers' => $headers,
            'json' => $body
        ]);
        $responseBody = $response->getBody()->getContents();
        $response = json_decode($responseBody);
        if ($response) {
            $this->MakeOutReport($response, $waId, $report, $user_id);
        }
        return $response;
    }
    private function sendTextMessage($waId, $customText, $url, $headers, $report, $user_id)
    {

        $response = (new Client())->post($url, [
            'headers' => $headers,
            'json' => [
                'messaging_product' => 'whatsapp',
                'to' => $waId,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $customText]
            ]
        ]);
        $responseBody = $response->getBody()->getContents();
        $response = json_decode($responseBody);
        //return response()->json(['response' => $response], 200);
        if ($response) {
            $this->MakeOutReport($response, $waId, $report, $user_id);
        }
    }
    protected function MakeOutReport($response, $waId, $report, $user_id)
    {
        $out_report = new OutReport();
        $out_report->user_id = $user_id;
        $out_report->display_phone_number = $report->display_phone_number;
        $out_report->phone_number_id = $report->phone_number_id;
        $out_report->status = 'sent';
        $out_report->status_id = $response->messages[0]->id;
        $out_report->recipient_id = $waId;
        $out_report->save();
        return response()->json(['response' => $out_report], 200);
    }
    protected function HandleReadMessage($data)
    {
        $reportData = $data['entry'][0]['changes'][0]['value'];
        $user = User::where('whatsapp_number', $reportData['metadata']['display_phone_number'])->first();

        if ($user->hasPermissionTo('Blue Tick Enabled', 'api')) {
            $readyApi = env('WA_API_READ_MESSAGE');
            $dynamic_url = str_replace([':whatsapp_phone_id:'], [$reportData['metadata']['phone_number_id']], $readyApi);
            $waToken = $user['userConfig']['meta_access_token'];
            $messageID = $reportData['messages'][0]['id'];
            $headers = [
                'Authorization' => 'Bearer ' . $waToken,
                'Content-Type' => 'application/json',
            ];

            // Define the request body
            $requestBody = [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $messageID,
            ];


            $response = Http::withHeaders($headers)->post($dynamic_url, $requestBody);
            $responseData = $response->getBody()->getContents();
            //return response()->json(['status' => $responseData], 200);
        }
    }
  
    public function IdleUserSession(Request $request)
    {
        try {
            $apiKey = $request->query('apiKey');
            $number = $request->query('number');
            // Check if API key is valid and active
            $apiKeyRecord = ApiKey::where('key', $apiKey)->where('status', 1)->first();

            if (!$apiKeyRecord) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid or inactive API key'
                ]);
            }
            $user = $apiKeyRecord->user;
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User associated with API key not found'
                ]);
            }

            // Check if user session exists
            $isUserSession = IdleMessageUser::where('user_id', $user->id)
                ->where('number', $number)
                ->exists();

            if ($isUserSession) {
                return response()->json([
                    'status' => true,
                    'message' => 'User Session is active'
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'User session expired'
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while processing your request. Please try again later.'
            ]);
        }
    }

}
