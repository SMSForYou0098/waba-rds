<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Events\ReportUpdated;
use App\Models\Report\Badge;
use App\Models\Report\OutReport;
use App\Models\Report\Report;
use App\Models\Report\ReportHasBadge;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ReportHandler extends Controller
{
    public function Reports(Request $request, $id)
    {
        try {
            $user = DB::table('users')
                ->where('id', $id)
                ->select('whatsapp_number')
                ->first();

            if (!$user) {
                return response()->json([
                    'error' => 'User not found'
                ], 404);
            }

            // Start building the query directly on the reports table
            $reports = DB::table('reports')
                ->where('display_phone_number', $user->whatsapp_number);

            // If no date range specified, use today's date
            if ($request->has('startDate') && $request->has('endDate')) {
                $startDate = Carbon::createFromFormat('d/m/y', $request->startDate)->startOfDay();
                $endDate = Carbon::createFromFormat('d/m/y', $request->endDate)->endOfDay();
            } else {
                $startDate = Carbon::today()->startOfDay();
                $endDate = Carbon::today()->endOfDay();
            }

            $reports->whereBetween('created_at', [$startDate, $endDate]);

            if ($request->has('search')) {
                $search = strtolower($request->search);
                $reports->where(function ($query) use ($search) {
                    $query->whereRaw('LOWER(profile_name) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(text_body) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(wa_id) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(display_phone_number) LIKE ?', ["%{$search}%"]);
                });
            }

            $filteredReports = $reports->select([
                'profile_name as Name',
                'text_body as Message',
                'wa_id as From',
                'display_phone_number as To',
                'timestamp as Date'
            ])->get();

            // Map the collection to custom headers
            $mappedReports = $filteredReports->map(function ($report) {
                return [
                    'Name' => $report->Name,
                    'Message' => $report->Message,
                    'From' => $report->From,
                    'To' => $report->To,
                    'Date' => $report->Date,
                ];
            });
            return response()->json($mappedReports, 200);
        } catch (Exception $e) {
            // Log the error or handle it appropriately
            return response()->json(['error' => 'An error occurred while fetching reports: ' . $e->getMessage()], 500);
        }
    }
    public function OutReports(Request $request, $id)
    {
        try {
            $query = OutReport::where('user_id', $id)->with('ApiReport');

            if ($request->filled(['startDate', 'endDate'])) {
                $startDate = Carbon::createFromFormat('d/m/y', $request->startDate)->startOfDay();
                $endDate = Carbon::createFromFormat('d/m/y', $request->endDate)->endOfDay();
            } else {
                $startDate = Carbon::today()->startOfDay();
                $endDate = Carbon::today()->endOfDay();
            }
            $query->whereBetween('created_at', [$startDate, $endDate]);

            if ($request->filled('search')) {
                $search = strtolower($request->search);
                $query->whereRaw('LOWER(recipient_id) LIKE ?', ["%{$search}%"]);
            }

            if ($request->filled('category')) {
                $category = strtolower($request->category);
                $query->where('category', $category);
            }

            $reports = $query->get();

            // Fetch balance counts in a separate query
            $balanceCounts = DB::table('balances')
                ->select('report_id', DB::raw('COUNT(*) as balance_count'))
                ->whereIn('report_id', $reports->pluck('id'))
                ->groupBy('report_id')
                ->pluck('balance_count', 'report_id');

            // Attach balance counts to reports
            $reports->each(function ($report) use ($balanceCounts) {
                $report->balance_count = $balanceCounts[$report->id] ?? 0;
            });

            return response()->json(['out_reports' => $reports], 200);

        } catch (Exception $e) {
            return response()->json(['error' => 'Error fetching reports' . $e->getMessage()], 500);
        }
    }
    public function MakeOutReport(Request $request)
    {
        $out_report = new OutReport();
        $out_report->user_id = $request->user_id;
        $out_report->display_phone_number = $request->display_phone_number;
        $out_report->recipient_id = $request->waId;
        $out_report->status_id = $request->status_id;
		$out_report->status = 'sent';
        $out_report->save();
        return response()->json(['status' => true, 'response' => $out_report], 200);
    }

public function LiveStatus(Request $request)
{
    $startDate = Carbon::today()->startOfDay();
    $endDate = Carbon::today()->endOfDay();
    $cacheKey = 'live_status_' . Carbon::today()->toDateString();

    if ($request->filled(['startDate', 'endDate'])) {
        $startDate = Carbon::createFromFormat('d/m/y', $request->startDate)->startOfDay();
        $endDate = Carbon::createFromFormat('d/m/y', $request->endDate)->endOfDay();
        $cacheKey = 'live_status_' . $request->startDate . '_' . $request->endDate;
        Cache::forget($cacheKey);
    }

    return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($startDate, $endDate) {
        try {
            // APPROACH 1: Get only users who have activity first (lightning fast)
            $activeUserIds = DB::table('out_reports')
                ->select('user_id')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->union(
                    DB::table('campaign_reports as cr')
                        ->select('c.user_id')
                        ->join('campaigns as c', 'cr.campaign_id', '=', 'c.id')
                        ->whereBetween('cr.created_at', [$startDate, $endDate])
                )
                ->distinct()
                ->pluck('user_id')
                ->toArray();

            if (empty($activeUserIds)) {
                return ['LiveStatus' => []];
            }

            // APPROACH 2: Get user details only for active users (super fast)
            $users = DB::table('users')
                ->select('id', 'name', 'company_name', 'whatsapp_number')
                ->whereIn('id', $activeUserIds)
                ->whereNotIn('id', function ($query) {
                    $query->select('model_id')
                        ->from('model_has_roles')
                        ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                        ->where('roles.name', 'Support Agent');
                })
                ->get()
                ->keyBy('id');

            $finalUserIds = $users->keys()->toArray();
            
            if (empty($finalUserIds)) {
                return ['LiveStatus' => []];
            }

            // APPROACH 3: Get balances for active users only (fast)
            $balances = DB::table('balances')
                ->select('user_id', 'total_credits')
                ->whereIn('user_id', $finalUserIds)
                ->orderBy('created_at', 'desc')
                ->get()
                ->groupBy('user_id')
                ->map(function ($userBalances) {
                    return $userBalances->first()->total_credits;
                });

            // APPROACH 4: Get incoming reports (fast)
            $incomingCounts = DB::table('reports as r')
                ->select('u.id as user_id', DB::raw('COUNT(*) as count'))
                ->join('users as u', 'r.display_phone_number', '=', 'u.whatsapp_number')
                ->whereBetween('r.timestamp', [$startDate->timestamp, $endDate->timestamp])
                ->whereIn('u.id', $finalUserIds)
                ->groupBy('u.id')
                ->pluck('count', 'user_id');

            // APPROACH 5: Get outgoing reports with all data (single query)
            $outgoingData = DB::table('out_reports')
                ->select('user_id', 'category', 'status', DB::raw('COUNT(*) as count'))
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereIn('user_id', $finalUserIds)
                ->groupBy('user_id', 'category', 'status')
                ->get();

            // APPROACH 6: Get campaign reports with all data (single query)
            $campaignData = DB::table('campaign_reports as cr')
                ->select('c.user_id', 'cr.status', DB::raw('COUNT(*) as count'))
                ->join('campaigns as c', 'cr.campaign_id', '=', 'c.id')
                ->whereBetween('cr.created_at', [$startDate, $endDate])
                ->whereIn('c.user_id', $finalUserIds)
                ->groupBy('c.user_id', 'cr.status')
                ->get();

            // APPROACH 7: Process data using PHP arrays (fastest processing)
            $result = [];
            
            foreach ($users as $userId => $user) {
                // Initialize counters
                $stats = [
                    'id' => $userId,
                    'name' => $user->name,
                    'company_name' => $user->company_name,
                    'date' => $startDate->toDateString(),
                    'balance' => (float) ($balances[$userId] ?? 0),
                    'total_incomingreports_count' => (int) ($incomingCounts[$userId] ?? 0),
                    'outreports_category_marketing_count' => 0,
                    'outreports_category_utility_count' => 0,
                    'outreports_category_service_count' => 0,
                    'outreports_category_auth_count' => 0,
                    'total_outreports_count' => 0,
                    'total_campaignreports_count' => 0,
                    'sentCount' => 0,
                    'deliveredCount' => 0,
                    'readCount' => 0,
                    'failCount' => 0,
                    'pendingCount' => 0,
                ];

                // Process outgoing reports
                foreach ($outgoingData as $row) {
                    if ($row->user_id != $userId) continue;
                    
                    $count = (int) $row->count;
                    $stats['total_outreports_count'] += $count;
                    
                    // Category counts
                    if ($row->category === 'marketing') $stats['outreports_category_marketing_count'] += $count;
                    elseif ($row->category === 'utility') $stats['outreports_category_utility_count'] += $count;
                    elseif ($row->category === 'service') $stats['outreports_category_service_count'] += $count;
                    elseif ($row->category === 'authentication') $stats['outreports_category_auth_count'] += $count;
                    
                    // Status counts
                    if ($row->status === 'sent') $stats['sentCount'] += $count;
                    elseif ($row->status === 'delivered') $stats['deliveredCount'] += $count;
                    elseif ($row->status === 'read') $stats['readCount'] += $count;
                    elseif ($row->status === 'failed') $stats['failCount'] += $count;
                    elseif ($row->status === 'pending') $stats['pendingCount'] += $count;
                }

                // Process campaign reports
                foreach ($campaignData as $row) {
                    if ($row->user_id != $userId) continue;
                    
                    $count = (int) $row->count;
                    $stats['total_campaignreports_count'] += $count;
                    
                    // Status counts
                    if ($row->status === 'sent') $stats['sentCount'] += $count;
                    elseif ($row->status === 'delivered') $stats['deliveredCount'] += $count;
                    elseif ($row->status === 'read') $stats['readCount'] += $count;
                    elseif ($row->status === 'failed') $stats['failCount'] += $count;
                    elseif ($row->status === 'pending') $stats['pendingCount'] += $count;
                }

                // Only include users with activity
                if ($stats['total_outreports_count'] > 0 || 
                    $stats['outreports_category_marketing_count'] > 0 ||
                    $stats['outreports_category_utility_count'] > 0 ||
                    $stats['outreports_category_service_count'] > 0 ||
                    $stats['total_campaignreports_count'] > 0) {
                    $result[] = $stats;
                }
            }

            return ['LiveStatus' => $result];

        } catch (\Exception $e) {
            return ['error' => 'An error occurred while fetching reports. ' . $e->getMessage()];
        }
    });
}

    private function calculateStatusCounts($outReportsGrouped, $campaignReportsGrouped)
    {
        // Initialize counts
        $statusCounts = [
            'sent' => 0,
            'delivered' => 0,
            'read' => 0,
            'failed' => 0,
            'pending' => 0,
        ];

        // Calculate counts for outReports
        foreach ($outReportsGrouped as $category => $statuses) {
            foreach ($statuses as $statusGroup) {
                foreach ($statusGroup as $report) {
                    if (isset($statusCounts[$report['status']])) {
                        $statusCounts[$report['status']] += $report['count'];
                    }
                }
            }
        }

        // Calculate counts for campaignReports
        foreach ($campaignReportsGrouped as $status => $reports) {
            foreach ($reports as $report) {
                if (isset($statusCounts[$status])) {
                    $statusCounts[$status] += $report->count; // Assuming the count is a property of the report object
                }
            }
        }

        return $statusCounts;
    }
    public function updateMediaUrl(Request $request, $id)
    {
        try {
            $report = Report::find($id);

            if (!$report) {
                return response()->json(['error' => 'Report not found'], 404);
            }

            $report->media_url = $request->media_url;
            $report->save();

            return response()->json(['message' => 'Media URL updated successfully', 'report' => $report], 200);

        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred while updating the media URL: ' . $e->getMessage()], 500);
        }
    }


    public function storeBadges(Request $request)
    {
        try {
            $userId = $request->user_id;
            $title = $request->title;
            $reportIds = $request->report_ids;
            $badgeId = $request->badge_id;
            $result = null;
            if ($badgeId) {
                // Find and update existing badge
                $badge = Badge::findOrFail($badgeId);
                $badge->title = $title;
                $badge->save();
                $result = $badge;
            } else {
                // Check if badge with this title already exists
                $existingBadge = Badge::where('title', $title)->first();
                if ($existingBadge) {
                    // Return existing badge
                    $result = $existingBadge;
                } else {
                    // Create new badge
                    $badge = Badge::create([
                        'title' => $title,
                        'user_id' => $userId
                    ]);
                    $result = $badge;
                }
            }
            // Automatically assign reports to this badge if report_ids are provided
            $assignmentResult = null;
            if ($result) {
                // Create a new request for assignReportsToBadge
                $assignmentRequest = new Request();
                $assignmentRequest->merge([
                    'user_id' => $userId,
                    'badge_id' => $result->id,
                    'report_ids' => $reportIds
                ]);

                // Call assignReportsToBadge method
                $assignmentResponse = $this->assignReportsToBadge($assignmentRequest, $result->id);
                $assignmentResult = $assignmentResponse->original;
            }

            return response()->json([
                'status' => true,
                'message' => 'Badges processed successfully and reports assigned',
                'badges' => $result,
                'assignment_result' => $assignmentResult
            ], 200);

        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred while processing badges: ' . $e->getMessage()], 500);
        }
    }
    public function assignReportsToBadge(Request $request, $badgeId)
    {
        try {
            $userId = $request->user_id;
            $badgeId = $request->badge_id;
            $reportIds = $request->report_ids;
            //return response()->json($reportIds, 500);
            $badge = Badge::findOrFail($badgeId);
            $result = [];
            ReportHasBadge::where('badge_id', $badgeId)->delete();
            // Otherwise, process the report IDs as before
            foreach ($reportIds as $reportId) {
                // Create relationship in report_has_badges table
                $reportHasBadge = ReportHasBadge::updateOrCreate(
                    [
                        'report_id' => $reportId,
                        'badge_id' => $badgeId
                    ],
                    ['user_id' => $userId]
                );

                $result[] = $reportHasBadge;
            }

            // Fetch all reports with this badge
            $reportsWithBadge = Report::whereHas('badges', function ($query) use ($badgeId) {
                $query->where('badges.id', $badgeId);
            })->get();

            return response()->json([
                'status' => true,
                'message' => 'Reports assigned to badge successfully',
                'badge' => $badge,
                'reports' => $reportsWithBadge,
                'report_has_badges' => $result
            ], 200);

        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred while assigning reports to badge: ' . $e->getMessage()], 500);
        }
    }

    public function assignBadgesToReport(Request $request, $reportId)
    {
        try {
            $result = [];
            $userId = $request->user_id;
            $badgeIds = $request->badge_ids;
            ReportHasBadge::where('report_id', $reportId)->delete();
            $result = [];

            foreach ($badgeIds as $badgeId) {
                // Create relationship in report_has_badges table
                $reportHasBadge = ReportHasBadge::updateOrCreate(
                    [
                        'report_id' => $reportId,
                        'badge_id' => $badgeId
                    ],
                    ['user_id' => $userId]
                );

                $result[] = $reportHasBadge;
            }

            $reportWithBadges = Report::select('id', 'wa_id')
                ->with([
                    'reportHasBadges' => function ($query) {
                        $query->select('id', 'report_id', 'badge_id');
                    },
                    'reportHasBadges.badge' => function ($query) {
                        $query->select('*'); // Select all fields from badges
                    }
                ])
                ->where('wa_id', $reportId)
                ->first();

            return response()->json([
                'status' => true,
                'message' => 'Badges assigned to report successfully',
                'report_has_badges' => $reportWithBadges
            ], 200);

        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred while assigning badges to report: ' . $e->getMessage()], 500);
        }
    }
public function getUserBadges(Request $request, $userId)
    {
        try {
            // Validate user exists
            $user = User::find($userId);
            $isSupportAgent = $user->hasRole('Support Agent');
            if (!$user) {
                return response()->json(['error' => 'User not found'], 404);
            }
            $id = $isSupportAgent ? $user->reporting_user : $userId;
            // Get all badges belonging to the user
            $badges = Badge::where('user_id', $id)
                ->with([
                    'reportHasBadges' => function ($query) {
                        // Only include essential report data
                        $query->select('id', 'report_id', 'badge_id');
                    }
                ])
                ->get();
            $transformedBadges = $badges->map(function ($badge) {
                // Extract just the report_ids into a simple array
                $reportIds = $badge->reportHasBadges->pluck('report_id')->toArray();

                // Create a clean badge object with the report_ids array
                return [
                    'id' => $badge->id,
                    'title' => $badge->title,
                    'user_id' => $badge->user_id,
                    'created_at' => $badge->created_at,
                    'updated_at' => $badge->updated_at,
                    'report_ids' => $reportIds,
                    'report_count' => count($reportIds)
                ];
            });
            return response()->json([
                'status' => true,
                'message' => 'User badges retrieved successfully',
                'user_id' => $userId,
                'badges' => $transformedBadges,
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'An error occurred while retrieving user badges: ' . $e->getMessage()
            ], 500);
        }
    }
    public function deleteBadge(Request $request, $badgeId)
    {
        try {
            // Find the badge
            $badge = Badge::findOrFail($badgeId);

            // Check if this badge belongs to the authenticated user (optional security check)
            if ($request->has('user_id') && $badge->user_id != $request->user_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You do not have permission to delete this badge'
                ], 403);
            }

            // Check if the badge has any associated reports
            $hasReports = ReportHasBadge::where('badge_id', $badgeId)->exists();

            if ($hasReports) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete badge: it is associated with one or more reports',
                    'badge_id' => $badgeId
                ], 400);
            }

            // No associations found, safe to delete
            $badge->delete(); // This will soft delete if SoftDeletes trait is used

            return response()->json([
                'status' => true,
                'message' => 'Badge deleted successfully',
                'badge_id' => $badgeId
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while deleting the badge: ' . $e->getMessage()
            ], 500);
        }
    }


}
