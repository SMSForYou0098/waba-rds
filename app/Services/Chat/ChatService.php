<?php

namespace App\Services\Chat;

use App\Models\Report\AgentHasReport;
use App\Models\Chat\ChatHistory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChatService
{
    /**
     * Retrieve chat data for a given user ID.
     */
    public function prepareChatData(User $user)
    {
        $id = $user->id;
        if ($user->hasRole('Support Agent')) {
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

            $reportIds = collect($reports)->pluck('id');
            $chats = \DB::table('chat_histories')
                ->whereIn('report_id', $reportIds)
                ->get()
                ->groupBy('report_id');

            $data = collect($reports)->map(function ($report) use ($chats) {
                $report->chats = $chats[$report->id] ?? collect();
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

            // Get chats in a single query
            $reportIds = $reports->pluck('id');
            $chats = \DB::table('chat_histories')
                ->whereIn('report_id', $reportIds)
                ->get()
                ->groupBy('report_id');

            $data = collect($reports)->map(function ($report) use ($chats) {
                $report->chats = $chats[$report->id] ?? collect();
                return $report;
            });
        }

        $data = $reports->map(function ($report) {
            $repliedChat = $report->chats;
            $firstChat = $repliedChat->isNotEmpty() ? $repliedChat->first() : null;
            $reportTime = $report->created_at;
            if ($firstChat && $firstChat->created_at > $report->created_at) {
                $chatWithRelation = ChatHistory::with('outReport')
                    ->find($firstChat->id);
                $latestMessage = [
                    'text' => $firstChat->message,
                    'status' => $chatWithRelation?->outReport?->status,
                    'from' => 'me',
                    "msgType" => $firstChat->type,
                    "id" => $firstChat->id,
                    "time" => $firstChat->created_at,
                ];
            } else {
                $latestMessage = [
                    'text' => $report->text_body,
                    'from' => 'opposite',
                    'status' => $report,
                    "msgType" => "text",
                    "id" => $report->id,
                    "time" => $report->created_at,
                ];
            }
            return [
                'number' => $report->wa_id,
                'name' => $report->profile_name,
                'chat' => $repliedChat,
                'msg' => [$latestMessage], // Use the latest message here
                'unread' => $report->status == 0 ? 1 : 0,
            ];
        });

        // Return all the data without pagination
        return response()->json(['data' => $data], 200);
    }
}
