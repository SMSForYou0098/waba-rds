<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Models\Report\AgentHasReport;
use App\Models\Chat\ChatHistory;
use App\Models\Report\Report;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use DB;
class NodeHelper extends Controller
{
    public function getExistingReport($messageId)
    {
        $campaignReport = DB::table('campaign_reports')->where('message_id', $messageId)->first();
        return response()->json([
            'exists' => $campaignReport ? true : false,
            'data' => $campaignReport
        ]);
    }

    public function getPricing(Request $request)
    {
        $category = $request->input('category', null);
        $whatsapp_number = $request->input('whatsapp_number');
        $cacheKey = 'pricing_' . $whatsapp_number;

        // Check cache first
        $cached = Cache::get($cacheKey);
        if ($cached) {
            if ($category) {
                return response()->json([
                    'category' => $category,
                    'price' => $cached['pricing'][$category] ?? 0
                ]);
            }
            return response()->json($cached);
        }

        // Fetch from DB if not cached
        $user = User::where('whatsapp_number', $whatsapp_number)
            ->with('pricingModel')
            ->first();

        if (!$user || !$user->pricingModel) {
            return response()->json(['error' => 'User or pricing not found'], 404);
        }

        $pricing = [
            'utility' => $user->pricingModel->utility_price ?? 0,
            'marketing' => $user->pricingModel->marketing_price ?? 0,
            'service' => $user->pricingModel->service_price ?? 0,
            'authentication' => $user->pricingModel->authentication_price ?? 0,
            'conversation' => $user->pricingModel->conversation_price ?? 0,
        ];

        $response = [
            'user_id' => $user->id,
            'whatsapp_number' => $user->whatsapp_number,
            'pricing' => $pricing,
        ];

        // Cache it for 15 minutes (or adjust as needed)
        Cache::put($cacheKey, $response, now()->addMinutes(30));

        if ($category) {
            return response()->json([
                'category' => $category,
                'price' => $pricing[$category] ?? 0
            ]);
        }

        return response()->json($response);
    }

    public function deduct(Request $request)
    {
        return DB::transaction(function () use ($request) {
            try {
                $whatsappNumber = $request->input('whatsapp_number');
                $category = $request->input('category');
                $messageId = $request->input('message_id');

                // Step 1: Check if this message is already billed (with row locking)
                $message = DB::table('out_reports')
                    ->where('status_id', $messageId)
                    ->lockForUpdate()
                    ->first();

                if ($message && $message->billable == 1) {
                    \Log::info('MESSAGE_ALREADY_BILLED', ['message_id' => $messageId]);
                    return response()->json([
                        'message' => 'Message already billed. No balance deducted.',
                        'billable' => true
                    ]);
                }

                // Step 2: Get user with row locking
                $user = User::where('whatsapp_number', $whatsappNumber)
                    ->with('pricingModel')
                    ->lockForUpdate()
                    ->first();

                if (!$user || !$user->pricingModel) {
                    \Log::error('USER_OR_PRICING_MODEL_NOT_FOUND', [
                        'whatsapp_number' => $whatsappNumber
                    ]);
                    return response()->json(['error' => 'User or pricing model not found'], 404);
                }

                // Step 3: Get price
                $cacheKey = "pricing_{$whatsappNumber}_{$category}";
                $price = Cache::get($cacheKey);

                if (is_null($price)) {
                    $priceColumn = $category . '_price';
                    $price = $user->pricingModel->{$priceColumn} ?? 0;
                    Cache::put($cacheKey, $price, now()->addMinutes(15));
                }

                // Validate price
                if ($price <= 0) {
                    \Log::warning('INVALID_PRICE', [
                        'price' => $price,
                        'category' => $category
                    ]);
                    return response()->json(['error' => 'Invalid price for category'], 400);
                }

                // Step 4: Get current balance with locking
                $latestBalance = DB::table('balances')
                    ->where('user_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->lockForUpdate()
                    ->first();

                $balance = $latestBalance ? $latestBalance->total_credits : 0;
                $newBalance = $balance - $price;

                if ($newBalance < 0) {
                    \Log::warning('INSUFFICIENT_BALANCE', [
                        'user_id' => $user->id,
                        'current_balance' => $balance,
                        'required_amount' => $price
                    ]);
                    return response()->json(['error' => 'Insufficient balance'], 400);
                }

                // Step 5: Insert new balance record
                DB::table('balances')->insert([
                    'user_id' => $user->id,
                    'new_credit' => $price,
                    'total_credits' => $newBalance,
                    'report_id' => $message->id ?? null,
                    'payment_type' => 'deduction',
                    'account_manager_id' => $user->reporting_user,
                    'auto_deduction' => 'true',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Step 6: Update message billable status (if message exists)
                if ($message) {
                    DB::table('out_reports')
                        ->where('id', $message->id)
                        ->update([
                            'billable' => 1,
                            'updated_at' => now()
                        ]);
                }

                return response()->json([
                    'message' => 'Balance deducted successfully',
                    'deducted' => $price,
                    'balance_before' => $balance,
                    'balance_after' => $newBalance,
                    'message_billed' => !is_null($message)
                ]);

            } catch (\Throwable $e) {
                \Log::error('DEDUCT_EXCEPTION', [
                    'error_message' => $e->getMessage(),
                    'whatsapp_number' => $request->input('whatsapp_number'),
                    'message_id' => $request->input('message_id')
                ]);

                throw $e; // Re-throw to trigger transaction rollback
            }
        });
    }



    public function update(Request $request)
    {
        $request->validate([
            'message_id' => 'required',
            'status' => 'required',
            'billable' => 'boolean',
        ]);

        // Extract inputs
        $message_id = $request->input('message_id');
        $status = $request->input('status');
        $billable = $request->input('billable', null);
        $conversation_id = $request->input('conversation_id', null);
        $expiration_timestamp = $request->input('expiration_timestamp', null);
      	$category = $request->input('category', null);
      	$timestamp = $request->input('time_stamp', null);
        $error_code = $request->input('error_code', null);

        $report = DB::table('out_reports')->where('status_id', $message_id)->first();

        if (!$report) {
            return response()->json(['error' => 'Report not found'], 404);
        }

        $update = [];
      
      	if($status === 'delivered') {
            $update['delivered_time'] = $timestamp;
        }else if ($status === 'read') {
            $update['read_time'] = $timestamp;
        }
      	if ($report->status !== $status && $report->status != 'read') {
            $update['status'] = $status;
        }

        if ($category) {
            $update['category'] = $category;
        }

        if (!is_null($conversation_id) && $report->conversation_id !== $conversation_id) {
            $update['conversation_id'] = $conversation_id;
        }

        if (!is_null($expiration_timestamp) && $report->expiration_timestamp !== $expiration_timestamp) {
            $update['expiration_timestamp'] = $expiration_timestamp;
        }

        if ($status === 'failed' && !is_null($error_code) && $report->error_code !== $error_code) {
            $update['error_code'] = $error_code;
        }

        //if ($report->billable != 1 && $report->billable !== ($billable ? 1 : 0)) {
        //    $update['billable'] = $billable ? 1 : 0;
        //}

        if (!empty($update)) {
            DB::table('out_reports')->where('id', $report->id)->update($update);
            return response()->json(['status' => true, 'message' => 'Report updated', 'updated_fields' => $update]);
        }

        return response()->json(['message' => 'No changes needed']);
    }
  
  	public function latestChat(Request $request)
{
    $waId = $request->query('wa_id');
    $displayPhone = $request->query('display_phone_number');
    
    if (!$waId || !$displayPhone) {
        return response()->json(['error' => 'Missing required parameters'], 422);
    }
    
    // Get the latest report matching wa_id and display_phone_number
    $report = DB::table('reports')
        ->where('wa_id', $waId)
        ->where('display_phone_number', $displayPhone)
        ->orderBy('created_at', 'desc')
        ->first();
        
    if (!$report) {
        return response()->json(['data' => null], 200);
    }
    
    // Get only the latest chat for this report (most recent message from "me")
    $latestChat = DB::table('chat_histories')
        ->where('report_id', $report->id)
        ->orderBy('created_at', 'desc')
        ->first();
    
    // Create the "opposite" message from the report
    $oppositeMessage = [
        'text' => $report->text_body,
        'from' => 'opposite',
        'status' => $report,
        'msgType' => 'text',
        'id' => $report->id,
        'time' => $report->created_at,
    ];
    
    // Determine the latest message (either from 'me' or 'opposite')
    $latestMessage = $oppositeMessage; // Default to opposite message
    
    // If there's a latest chat (me message), check if it's newer
    if ($latestChat && strtotime($latestChat->created_at) > strtotime($report->created_at)) {
        $chatWithRelation = ChatHistory::with('outReport')
            ->find($latestChat->id);
        
        $latestMessage = [
            'text' => $latestChat->message,
            'status' => $chatWithRelation?->outReport?->status ?? 'delivered',
            'status_id' => $chatWithRelation?->outReport?->status_id ?? $latestChat->message_id,
            'from' => 'me',
            'msgType' => $latestChat->type,
            'id' => $latestChat->id,
            'time' => $latestChat->created_at,
        ];
    }
    
    // Final response structure - matching the updated Chats function structure
    $response = [
        'number' => $report->wa_id,
        'name' => $report->profile_name,
        'lastMessage' => $latestMessage, // Only the latest message
        'unread' => $report->status == 0 ? 1 : 0,
    ];
    
    return response()->json(['data' => $response], 200);
}

  

    public function getSingleChat(Request $request)
{
    try {
        $type = $request->input('type');
        
        if ($type === 'incoming') {
            $waId = $request->input('wa_id');
            $displayPhone = $request->input('display_phone_number');
            
            // 🔍 Find latest incoming report
            $report = DB::table('reports')
                ->where('wa_id', $waId)
                ->where('display_phone_number', $displayPhone)
                ->orderByDesc('created_at')
                ->first();
                
            if (!$report) {
                return response()->json(['error' => 'No incoming report found'], 404);
            }
            
            $message = [
                'type' => 'text',
                'text' => $report->text_body,
                'from' => 'opposite',
                'msgType' => $report->messages_type,
                'media_id' => $report->media_id,
                'media_url' => $report->media_url,
                'id' => $report->id,
                'mesgTime' => Carbon::parse($report->created_at)->format('h:i A'),
                'created_at' => $report->created_at,
            ];
            
            return response()->json(['message' => $message]);
        }
        
        if ($type === 'outgoing') {
            $messageId = $request->input('message_id');
            
            // Try to find the out_report
            $outReport = DB::table('out_reports')
                ->where('status_id', $messageId)
                ->where('status', '!=', 0)
                ->first();
                
            $status = 'pending';
            $reportId = null;
            
            if ($outReport) {
                $status = $outReport->status;
                $reportId = $outReport->id;
            }
            
            // Try to find chat by report_id if outReport found, else by message_id
            $chatQuery = ChatHistory::query();
            if ($reportId) {
                $chatQuery->where('report_id', $reportId);
            } else {
                $chatQuery->where('message_id', $messageId);
            }
            
            $chat = $chatQuery->with(['outReport', 'supportAgentData'])->orderByDesc('created_at')->first();
            
            if (!$chat) {
                return response()->json(['error' => 'Chat history not found for this message'], 404);
            }
            
            $message = [
                'type' => $chat->type,
                'text' => $chat->message,
                'from' => 'me',
                'agent_activeChatCount' => $chat->agent_id ? $this->getActiveChatCountByAgent($chat->agent_id) : null,
                'agent_id' => $chat->supportAgentData,
                'msgType' => 'text',
                'status' => $chat?->outReport?->status,
                'id' => $chat->id,
                'mesgTime' => Carbon::parse($chat->created_at)->format('h:i A'),
                'created_at' => $chat->created_at,
            ];
            
            return response()->json(['message' => $message]);
        }
        
        return response()->json(['error' => 'Invalid type'], 400);
        
    } catch (\Throwable $e) {
        return response()->json(['error' => $e->getMessage(), 'details' => $e->getMessage()], 500);
    }
}
  
    private function getActiveChatCountByAgent($agentId)
    {
        // Get the last 24 hours' timestamp
        $last24Hours = now()->subDay();

        // Get the wa_ids for the given agent_id from the AgentHasReport model
        $waIds = AgentHasReport::where('agent_id', $agentId)
            ->pluck('wa_id'); // Get the wa_id(s) associated with the agent_id

        // Count the distinct wa_id from the Report model created in the last 24 hours
        $activeChatCount = Report::whereIn('wa_id', $waIds)
            ->where('created_at', '>=', $last24Hours)
            ->distinct('wa_id') // Count unique wa_ids
            ->count();

        return $activeChatCount;
    }

}
