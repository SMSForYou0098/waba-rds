<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\ChatHistory;
use App\Models\Report\Report;
use App\Models\Report\AgentHasReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
      public function __construct()
      {
          $this->middleware('auth');
      }
    public function ChatsWithPagination($id)
    {
        $messages = User::findOrFail($id);

        // Number of reports to return per page
        $perPage = 10;

        // Get the current page from the request, defaulting to 1
        $currentPage = request()->get('page', 1);

        $subquery = \DB::table('reports')
            ->select('wa_id', \DB::raw('MAX(created_at) as latest'))
            ->groupBy('wa_id');
        // Query to fetch the latest report for each unique user number
        $reports = $messages->reports()
            ->joinSub($subquery, 'latest_reports', function ($join) {
                $join->on('reports.wa_id', '=', 'latest_reports.wa_id')
                    ->on('reports.created_at', '=', 'latest_reports.latest');
            })
            ->select('reports.wa_id', 'reports.profile_name', 'reports.status', 'reports.text_body', 'reports.id', 'reports.created_at')
            ->orderBy('reports.created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $currentPage);

        $data = [];

        // Iterate through reports to build response data
        foreach ($reports as $report) {
            $userNumber = $report->wa_id;

            $data[] = [
                'number' => $userNumber,
                'name' => $report->profile_name,
                'msg' => [
                    [
                        'text' => $report->text_body,
                        'from' => 'opposite',
                        "msgType" => "text",
                        "id" => $report->id,
                        "time" => $report->created_at->toDateTimeString(),
                    ]
                ],
                'unread' => $report->status == 0 ? 1 : 0,
            ];
        }

        // Return the paginated response with one entry per userNumber
        return response()->json([
            'data' => $data,
            'current_page' => $reports->currentPage(),
            'total_pages' => $reports->lastPage(),
            'has_more' => $reports->hasMorePages(),
        ], 200);
    }
  

    public function Chats($id)
{
    // Retrieve the user along with their latest report for each unique wa_id
    $user = User::findOrFail($id);

    if ($user->hasRole('Support Agent')) {
        // Keep the fast DB::table query but add relationship data in an optimized way
        $agentReports = AgentHasReport::where('agent_id', $id)
            ->select('wa_id', 'display_phone_number')
            ->get();

        $subquery = \DB::table('reports')
            ->select('wa_id', 'display_phone_number', \DB::raw('MAX(created_at) as latest'))
            ->whereIn('wa_id', $agentReports->pluck('wa_id'))
            ->whereIn('display_phone_number', $agentReports->pluck('display_phone_number'))
            ->groupBy('wa_id', 'display_phone_number');

        $reports = \DB::table('reports')
            ->joinSub($subquery, 'latest_reports', function ($join) {
                $join->on('reports.wa_id', '=', 'latest_reports.wa_id')
                    ->on('reports.display_phone_number', '=', 'latest_reports.display_phone_number')
                    ->on('reports.created_at', '=', 'latest_reports.latest');
            })
            ->select('reports.*')
            ->orderBy('reports.created_at', 'desc')
            ->get();

        // Get only the latest chat for each report (most recent "me" message)
        $reportIds = collect($reports)->pluck('id');
        $latestChats = \DB::table('chat_histories')
            ->whereIn('report_id', $reportIds)
            ->whereRaw('(report_id, created_at) IN (
                SELECT report_id, MAX(created_at) 
                FROM chat_histories 
                WHERE report_id IN (' . $reportIds->implode(',') . ') 
                GROUP BY report_id
            )')
            ->get()
            ->keyBy('report_id');

        // Get unread counts for each wa_id + display_phone_number combination
        $unreadCounts = \DB::table('reports')
            ->whereIn('wa_id', $agentReports->pluck('wa_id'))
            ->whereIn('display_phone_number', $agentReports->pluck('display_phone_number'))
            ->where('status', 0) // Only unread reports
            ->select('wa_id', 'display_phone_number', \DB::raw('COUNT(*) as unread_count'))
            ->groupBy('wa_id', 'display_phone_number')
            ->get()
            ->keyBy(function($item) {
                return $item->wa_id . '_' . $item->display_phone_number;
            });

        // Get all badges created by the reporting user (the user who created the reports)
        $reportingUser = $user->reportingUser;
        $allBadges = collect();
        if ($reportingUser) {
            $allBadges = \DB::table('badges')
                ->where('user_id', $reportingUser->id)
                ->get();
        }

        // Get report_has_badges for these reports
        $reportHasBadges = \DB::table('report_has_badges')
            ->whereIn('report_id', $reportIds)
            ->get();

        // Attach latest chat and badges info to each report
        $reports = collect($reports)->map(function ($report) use ($latestChats, $allBadges, $reportHasBadges, $unreadCounts) {
            // Get only the latest chat for this report
            $report->chats = $latestChats[$report->id] ?? null;
            
            // Get unread count for this specific wa_id + display_phone_number
            $unreadKey = $report->wa_id . '_' . $report->display_phone_number;
            $report->unread_count = $unreadCounts[$unreadKey]->unread_count ?? 0;
            
            // For this report, get the badge_ids assigned
            $assignedBadgeIds = $reportHasBadges->where('report_id', $report->id)->pluck('badge_id')->toArray();
            // Attach all badges, marking which are assigned
            $report->badges = $allBadges->map(function ($badge) use ($assignedBadgeIds) {
                $badge = (array) $badge;
                $badge['assigned'] = in_array($badge['id'], $assignedBadgeIds);
                return $badge;
            });
            return $report;
        });

    } else {
        // Original logic for non-agent users
        $subquery = \DB::table('reports')
            ->select('wa_id', \DB::raw('MAX(created_at) as latest'))
            ->groupBy('wa_id');

        $reports = $user->reports()
            ->joinSub($subquery, 'latest_reports', function ($join) {
                $join->on('reports.wa_id', '=', 'latest_reports.wa_id')
                    ->on('reports.created_at', '=', 'latest_reports.latest');
            })
            ->orderBy('reports.created_at', 'desc')
            ->get();

        // Get only the latest chat for each report
        $reportIds = $reports->pluck('id');
        $latestChats = \DB::table('chat_histories')
            ->whereIn('report_id', $reportIds)
            ->whereRaw('(report_id, created_at) IN (
                SELECT report_id, MAX(created_at) 
                FROM chat_histories 
                WHERE report_id IN (' . $reportIds->implode(',') . ') 
                GROUP BY report_id
            )')
            ->get()
            ->keyBy('report_id');

        // Get unread counts for each wa_id
        $unreadCounts = $user->reports()
            ->where('status', 0)
            ->select('wa_id', \DB::raw('COUNT(*) as unread_count'))
            ->groupBy('wa_id')
            ->get()
            ->keyBy('wa_id');

        // Load badges for these reports
        $badges = \DB::table('report_has_badges')
            ->join('badges', 'report_has_badges.badge_id', '=', 'badges.id')
            ->whereIn('report_has_badges.report_id', $reportIds)
            ->select('report_has_badges.report_id', 'badges.*')
            ->get()
            ->groupBy('report_id');

        $reports = collect($reports)->map(function ($report) use ($latestChats, $unreadCounts) {
            $report->chats = $latestChats[$report->id] ?? null;
            $report->unread_count = $unreadCounts[$report->wa_id]->unread_count ?? 0;
            return $report;
        });
    }

    $data = $reports->map(function ($report) {
        $latestChat = $report->chats; // This is the latest "me" message
        
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
        
        return [
            'number' => $report->wa_id,
            'name' => $report->profile_name,
            'lastMessage' => $latestMessage, // Only the latest message for chat menu
            'unread' => $report->unread_count, // Total unread count instead of 0/1
        ];
    });

    // Return all the data without pagination
    return response()->json(['data' => $data], 200);
}

    public function NewChat(Request $request, $id)
    {
         $chat = new ChatHistory();
        $chat->user_id = $request->id;
        $chat->message = $request->message;
        $chat->message_id = $request->message_id;
        $chat->type = $request->type;
        $chat->agent_id = $request->agent_id;
        $chat->out_report_id = $request->out_report_id;
        $chat->reply_id = $request->reply_id;
        $chat->report_id = $request->report_id;
        $chat->save();
        return response()->json(['status' => true, 'message' => 'message send succesfully'], 200);
    }


    public function getMessagesByNumber($number)
{
    $loggedInUser = Auth::user();
   
    $loggedInUser = Auth::user();

    if ($loggedInUser->hasRole('Support Agent')) {
        $reportingUser = $loggedInUser->reportingUser;
        if (!$reportingUser) {
            return response()->json(['error' => 'Reporting user not found'], 404);
        }
        $userConfig = $reportingUser->userConfig;
    } else {
        // For non-support agents, use their own configuration
        $userConfig = $loggedInUser->userConfig;
    }
    
    $reports = Report::with('chats')
        ->where('wa_id', $number)
        ->where('phone_number_id', $userConfig->whatsapp_phone_id)
        ->orderBy('created_at', 'asc')
        ->get();

    if ($reports->isEmpty()) {
        return response()->json(['status' => false, 'message' => 'No messages found for this number'], 404);
    }
    
    $activeAgent = $reports[0]->activeAgent ?? null;
    $data = [
        'number' => $number,
        'name' => $reports->first()->profile_name,
        'active_agent' => $activeAgent,
        'msg' => [],
        'unread' => 0,
    ];

    $today = Carbon::now()->format('Y-m-d');
    $yesterday = Carbon::now()->subDay()->format('Y-m-d');
    $unreadIds = [];

    // Collect all messages (reports and chats) in a single array
    $allMessages = collect();

    foreach ($reports as $report) {
        if ($report->status == 0) {
            $data['unread']++;
            $unreadIds[] = $report->id;
        }

        // Add report message
        $allMessages->push([
            'type' => 'text',
            'text' => $report?->text_body,
            'from' => 'opposite',
            'msgType' => $report?->messages_type,
            'media_id' => $report?->media_id,
            'media_url' => $report?->media_url,
            'id' => $report->id,
            'created_at' => $report->created_at,
            'message_source' => 'report'
        ]);

        // Add chat messages for this report
        foreach ($report->chats as $chat) {
            $allMessages->push([
                'type' => $chat->type,
                'text' => $chat->message,
                'from' => 'me',
                'agent_activeChatCount' => $chat->agent_id ? $this->getActiveChatCountByAgent($chat->agent_id) : null,
                'agent_id' => $chat->supportAgentData,
                'msgType' => 'text',
                'status' => $chat?->outReport?->status,
                'id' => $chat->id,
                'created_at' => $chat->created_at,
                'message_source' => 'chat'
            ]);
        }
    }

    // Sort all messages by created_at timestamp
    $sortedMessages = $allMessages->sortBy('created_at');

    // Now process the sorted messages and add date separators
    $lastDate = null;
    foreach ($sortedMessages as $message) {
        $messageDate = Carbon::parse($message['created_at'])->format('Y-m-d');
        $dateLabel = $this->formatDateLabel($messageDate, $today, $yesterday);

        // Add date separator if date changed
        if ($lastDate !== $dateLabel) {
            $data['msg'][] = [
                'text' => '',
                'from' => '',
                'msgType' => 'date',
                'id' => null,
                'mesgTime' => '',
                'created_at' => $dateLabel,
            ];
            $lastDate = $dateLabel;
        }

        // Add the message with formatted time
        $messageData = [
            'type' => $message['type'],
            'text' => $message['text'],
            'from' => $message['from'],
            'msgType' => $message['msgType'],
            'id' => $message['id'],
            'mesgTime' => Carbon::parse($message['created_at'])->format('h:i A'),
            'created_at' => $message['created_at'],
        ];

        // Add additional fields based on message source
        if ($message['message_source'] === 'report') {
            $messageData['media_id'] = $message['media_id'];
            $messageData['media_url'] = $message['media_url'];
        } else {
            $messageData['agent_activeChatCount'] = $message['agent_activeChatCount'];
            $messageData['agent_id'] = $message['agent_id'];
            $messageData['status'] = $message['status'];
        }

        $data['msg'][] = $messageData;
    }

    // Update the status of all unread reports in a single query
    if (!empty($unreadIds)) {
        Report::whereIn('id', $unreadIds)->update(['status' => 1]);
    }

    return response()->json($data, 200);
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
  	
  
public function getMessagesByNumberPaginated($number, Request $request)
{
    $loggedInUser = Auth::user();
    $perPage = $request->get('per_page', 20);
    $page = $request->get('page', 1);

    if ($loggedInUser->hasRole('Support Agent')) {
        $reportingUser = $loggedInUser->reportingUser;
        if (!$reportingUser) {
            return response()->json(['error' => 'Reporting user not found'], 404);
        }
        $userConfig = $reportingUser->userConfig;
    } else {
        $userConfig = $loggedInUser->userConfig;
    }

    // For page 1, get latest reports. For subsequent pages, get older reports
    $reports = Report::with('chats')
        ->where('wa_id', $number)
        ->where('phone_number_id', $userConfig->whatsapp_phone_id)
        ->orderBy('created_at', 'desc') // Latest first for pagination
        ->skip(($page - 1) * $perPage)
        ->take($perPage)
        ->get()
        ->reverse(); // Reverse to maintain chronological order within the batch

    if ($reports->isEmpty() && $page == 1) {
        return response()->json(['status' => false, 'message' => 'No messages found for this number'], 404);
    }

    // Get active agent from the latest report
    $activeAgent = null;
    if (!$reports->isEmpty()) {
        $activeAgent = $reports->first()->activeAgent ?? null;
    }

    // Use same structure as original method
    $data = [
        'number' => $number,
        'name' => $reports->first()->profile_name ?? '',
        'active_agent' => $activeAgent,
        'msg' => [], // Keep same key name as original
        'unread' => 0,
    ];

    $today = Carbon::now()->format('Y-m-d');
    $yesterday = Carbon::now()->subDay()->format('Y-m-d');
    $unreadIds = [];

    // Collect all messages for this batch
    $allMessages = collect();

    foreach ($reports as $report) {
        if ($page == 1 && $report->status == 0) {
            $data['unread']++;
            $unreadIds[] = $report->id;
        }

        // Add report message
        $allMessages->push([
            'type' => 'text',
            'text' => $report?->text_body,
            'from' => 'opposite',
            'msgType' => $report?->messages_type,
            'media_id' => $report?->media_id,
            'media_url' => $report?->media_url,
            'id' => $report->id,
            'created_at' => $report->created_at,
            'message_source' => 'report'
        ]);

        // Add chat messages for this report
        foreach ($report->chats as $chat) {
            $allMessages->push([
                'type' => $chat->type,
                'text' => $chat->message,
                'from' => 'me',
                'agent_activeChatCount' => $chat->agent_id ? $this->getActiveChatCountByAgent($chat->agent_id) : null,
                'agent_id' => $chat->supportAgentData,
                'msgType' => 'text',
                'status' => $chat?->outReport?->status,
                'id' => $chat->id,
                'created_at' => $chat->created_at,
                'message_source' => 'chat'
            ]);
        }
    }

    // Sort messages by created_at timestamp
    $sortedMessages = $allMessages->sortBy('created_at');

    // Process sorted messages and add date separators
    $lastDate = null;
    foreach ($sortedMessages as $message) {
        $messageDate = Carbon::parse($message['created_at'])->format('Y-m-d');
        $dateLabel = $this->formatDateLabel($messageDate, $today, $yesterday);

        // Add date separator if date changed
        if ($lastDate !== $dateLabel) {
            $data['msg'][] = [
                'text' => '',
                'from' => '',
                'msgType' => 'date',
                'id' => null,
                'mesgTime' => '',
                'created_at' => $dateLabel,
            ];
            $lastDate = $dateLabel;
        }

        // Add the message with formatted time - same structure as original
        $messageData = [
            'type' => $message['type'],
            'text' => $message['text'],
            'from' => $message['from'],
            'msgType' => $message['msgType'],
            'id' => $message['id'],
            'mesgTime' => Carbon::parse($message['created_at'])->format('h:i A'),
            'created_at' => $message['created_at'],
        ];

        // Add additional fields based on message source
        if ($message['message_source'] === 'report') {
            $messageData['media_id'] = $message['media_id'];
            $messageData['media_url'] = $message['media_url'];
        } else {
            $messageData['agent_activeChatCount'] = $message['agent_activeChatCount'];
            $messageData['agent_id'] = $message['agent_id'];
            $messageData['status'] = $message['status'];
        }

        $data['msg'][] = $messageData;
    }

    // Update unread status only on first page
    if ($page == 1 && !empty($unreadIds)) {
        Report::whereIn('id', $unreadIds)->update(['status' => 1]);
    }

    // Add pagination metadata without breaking the original structure
    $totalReports = Report::where('wa_id', $number)
        ->where('phone_number_id', $userConfig->whatsapp_phone_id)
        ->count();
    
    $data['has_more'] = ($page * $perPage) < $totalReports;

    return response()->json($data, 200);
}

  
    private function formatDateLabel($date, $today, $yesterday)
    {
        if ($date === $today) {
            return 'Today';
        } elseif ($date === $yesterday) {
            return 'Yesterday';
        } else {
            return Carbon::parse($date)->format('F d, Y');
        }
    }
}
