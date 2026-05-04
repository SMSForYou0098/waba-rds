<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GlobalReportsController extends Controller
{
    /**
     * Ultra-fast method for large datasets using raw SQL
     * Use this when you have millions of records
     */
    public function getCombinedReportsFast(Request $request)
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();
            if (!$authUser) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Get and validate request parameters
            $filters = $this->validateAndParseFilters($request);
            if (isset($filters['error'])) {
                return response()->json(['error' => $filters['error']], 400);
            }

            // Apply role-based filtering
            $roleBasedFilters = $this->applyRoleBasedFiltering($authUser, $filters);
            if (isset($roleBasedFilters['error'])) {
                return response()->json(['error' => $roleBasedFilters['error']], 403);
            }

            // Parse date range
            $dateRange = $this->parseDateRange($request);

            // Use raw SQL for maximum performance
            $combinedReports = $this->getCombinedReportsRaw($dateRange, $roleBasedFilters);

            // Generate summary directly from query results
            $summary = $this->generateFastSummary($combinedReports, $dateRange, $roleBasedFilters);

            // Add debug information to help troubleshoot
            $debug = [
                'date_range_used' => [
                    'start' => $dateRange['start']->format('Y-m-d H:i:s'),
                    'end' => $dateRange['end']->format('Y-m-d H:i:s'),
                    'type' => $dateRange['type']
                ],
                'date_parsing_debug' => [
                    'raw_startDate' => $request->input('startDate'),
                    'raw_endDate' => $request->input('endDate'),
                    'parsed_start' => $dateRange['start']->format('Y-m-d H:i:s'),
                    'parsed_end' => $dateRange['end']->format('Y-m-d H:i:s'),
                ],
                'filters_used' => $roleBasedFilters,
                'auth_user_role' => $authUser->getRoleNames()->first(),
                'total_found' => count($combinedReports),
                'current_timestamp' => Carbon::now()->format('Y-m-d H:i:s'),
                'today_date' => Carbon::today()->format('Y-m-d'),
                'request_parameters' => [
                    'startDate' => $request->input('startDate'),
                    'endDate' => $request->input('endDate'),
                    'date_range' => $request->input('date_range'),
                    'user_id' => $request->input('user_id'),
                    'no_date_filter' => $request->input('no_date_filter')
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'reports' => $combinedReports,
                    'summary' => $summary
                ],
                'debug' => $debug
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'An error occurred while fetching reports: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate and parse request filters
     */
    private function validateAndParseFilters(Request $request): array
    {
        $userId = $request->input('user_id');
        $category = $request->input('category');
        $reportType = $request->input('report_type'); // New filter
        $status = $request->input('status'); // New status filter
        $search = $request->input('search'); // New search filter

        // Validate category if provided
        $validCategories = ['service', 'marketing', 'utility', 'authentication'];
        if ($category && !in_array($category, $validCategories)) {
            return ['error' => 'Invalid category. Valid values are: ' . implode(', ', $validCategories)];
        }

        // Validate report_type if provided
        $validReportTypes = ['out_report', 'campaign_report'];
        if ($reportType && !in_array($reportType, $validReportTypes)) {
            return ['error' => 'Invalid report_type. Valid values are: ' . implode(', ', $validReportTypes)];
        }

        // Validate status if provided
        $validStatuses = ['sent', 'delivered', 'read', 'failed', 'pending', 'queued', 'accepted'];
        if ($status && !in_array($status, $validStatuses)) {
            return ['error' => 'Invalid status. Valid values are: ' . implode(', ', $validStatuses)];
        }

        return [
            'user_id' => $userId,
            'category' => $category,
            'report_type' => $reportType,
            'status' => $status,
            'search' => $search
        ];
    }

    /**
     * Apply role-based filtering based on authenticated user's role
     */
    private function applyRoleBasedFiltering($authUser, array $filters): array
    {
        // Get user role (assuming first role if multiple)
        $userRole = $authUser->getRoleNames()->first();

        if (!$userRole) {
            return ['error' => 'User has no assigned role'];
        }

        switch (strtolower($userRole)) {
            case 'admin':
                // Admin can see all data, no additional filtering
                $filters['role_type'] = 'admin';
                $filters['allowed_user_ids'] = null; // No restriction
                break;

            case 'reseller':
                // Reseller can see data for users where reporting_user = reseller's ID
                $filters['role_type'] = 'reseller';
                $filters['reseller_id'] = $authUser->id;
                $filters['allowed_user_ids'] = null; // Will be handled in SQL
                break;

            case 'support agent':
                // Support agent can see data for users under their reporting_user
                if (!$authUser->reporting_user) {
                    return ['error' => 'Support Agent has no reporting_user assigned'];
                }
                $filters['role_type'] = 'reseller'; // Use same logic as reseller
                $filters['reseller_id'] = $authUser->reporting_user; // Use their reporting_user
                $filters['allowed_user_ids'] = null; // Will be handled in SQL
                break;

            case 'user':
                // User can only see their own data based on WhatsApp number
                if (!$authUser->whatsapp_number) {
                    return ['error' => 'User has no WhatsApp number assigned'];
                }
                $filters['role_type'] = 'user';
                $filters['user_whatsapp_number'] = $authUser->whatsapp_number;
                $filters['allowed_user_ids'] = [$authUser->id]; // Only their own data
                break;

            default:
                return ['error' => 'Invalid user role: ' . $userRole];
        }

        return $filters;
    }

    /**
     * Parse date range from request
     */
    private function parseDateRange(Request $request): array
    {
        $dateRange = $request->input('date_range');
        $startDate = $request->input('startDate'); // Frontend format
        $endDate = $request->input('endDate');     // Frontend format
        $noDateFilter = $request->input('no_date_filter'); // For debugging

        // For debugging: disable date filter completely
        if ($noDateFilter) {
            return [
                'start' => Carbon::parse('2020-01-01')->startOfDay(),
                'end' => Carbon::parse('2030-12-31')->endOfDay(),
                'type' => 'all'
            ];
        }

        // Priority 1: Check startDate and endDate parameters (Frontend format)
        if ($startDate || $endDate) {
            if ($startDate && $endDate) {
                // Handle DD/MM/YY format specifically
                $start = $this->parseCustomDateFormat($startDate);
                $end = $this->parseCustomDateFormat($endDate);

                return [
                    'start' => $start->startOfDay(),
                    'end' => $end->endOfDay(),
                    'type' => $start->isSameDay($end) ? 'single' : 'range'
                ];
            } elseif ($startDate) {
                $date = $this->parseCustomDateFormat($startDate);
                return [
                    'start' => $date->startOfDay(),
                    'end' => $date->endOfDay(),
                    'type' => 'single'
                ];
            } elseif ($endDate) {
                $date = $this->parseCustomDateFormat($endDate);
                return [
                    'start' => $date->startOfDay(),
                    'end' => $date->endOfDay(),
                    'type' => 'single'
                ];
            }
        }

        // Priority 2: Check if date_range parameter is provided (Alternative format)
        if ($dateRange) {
            // If it's a single date
            if (is_string($dateRange) && !str_contains($dateRange, ',')) {
                $date = Carbon::parse($dateRange);
                return [
                    'start' => $date->startOfDay(),
                    'end' => $date->endOfDay(),
                    'type' => 'single'
                ];
            }

            // If it's a range (comma-separated)
            if (is_string($dateRange) && str_contains($dateRange, ',')) {
                $dates = explode(',', $dateRange);
                $start = Carbon::parse(trim($dates[0]));
                $end = isset($dates[1]) ? Carbon::parse(trim($dates[1])) : $start;

                return [
                    'start' => $start->startOfDay(),
                    'end' => $end->endOfDay(),
                    'type' => $start->isSameDay($end) ? 'single' : 'range'
                ];
            }
        }

        // Default: Today's date when no specific date is provided
        return [
            'start' => Carbon::today()->startOfDay(),
            'end' => Carbon::today()->endOfDay(),
            'type' => 'today_default'
        ];
    }

    /**
     * Get combined reports using optimized raw SQL for best performance
     */
    private function getCombinedReportsRaw(array $dateRange, array $filters)
    {
        $dbCategory = null;
        if ($filters['category']) {
            $dbCategory = $filters['category'] === 'authentication' ? 'authentication' : $filters['category'];
        }

        // Build dynamic WHERE clauses
        $outReportsUserFilter = $filters['user_id'] ? 'AND or_table.user_id = ?' : '';
        $outReportsCategoryFilter = $filters['category'] ? 'AND or_table.category = ?' : '';
        $outReportsStatusFilter = $filters['status'] ? 'AND or_table.status = ?' : '';

        // Apply role-based filtering for out_reports
        $outReportsRoleFilter = '';
        switch ($filters['role_type'] ?? 'admin') {
            case 'reseller':
                $outReportsRoleFilter = 'AND u.reporting_user = ?';
                break;
            case 'user':
                // User can see their own reports by user_id or by whatsapp_number match
                $outReportsRoleFilter = 'AND (or_table.user_id = ? OR or_table.display_phone_number = ?)';
                break;
            case 'admin':
            default:
                // No additional filtering for admin
                break;
        }

        // Build search filter for out_reports
        $outReportsSearchFilter = '';
        if ($filters['search']) {
            $outReportsSearchFilter = 'AND (or_table.recipient_id LIKE ? OR or_table.display_phone_number LIKE ? OR u.company_name LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
        }

        $campaignReportsUserFilter = $filters['user_id'] ? 'AND (c.user_id = ? OR u.id = ?)' : '';
        $campaignReportsCategoryFilter = $filters['category'] ? 'AND cr.template_category = ?' : '';
        $campaignReportsStatusFilter = $filters['status'] ? 'AND cr.status = ?' : '';

        // Apply role-based filtering for campaign_reports
        $campaignReportsRoleFilter = '';
        switch ($filters['role_type'] ?? 'admin') {
            case 'reseller':
                $campaignReportsRoleFilter = 'AND u.reporting_user = ?';
                break;
            case 'user':
                // User can see campaigns they own or campaigns associated with their whatsapp number
                $campaignReportsRoleFilter = 'AND (c.user_id = ? OR u.whatsapp_number = ?)';
                break;
            case 'admin':
            default:
                // No additional filtering for admin
                break;
        }

        // Build search filter for campaign_reports
        $campaignReportsSearchFilter = '';
        if ($filters['search']) {
            $campaignReportsSearchFilter = 'AND (cr.mobile_number LIKE ? OR u.whatsapp_number LIKE ? OR u.company_name LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
        }

        // Handle report type filter
        $includeOutReports = !$filters['report_type'] || $filters['report_type'] === 'out_report';
        $includeCampaignReports = !$filters['report_type'] || $filters['report_type'] === 'campaign_report';

        $unionParts = [];

        // Add out_reports query if needed
        if ($includeOutReports) {
            $unionParts[] = "
                (
                    SELECT
                        or_table.id,
                        'out_report' as type,
                        u.id as user_id,
                        u.name as user_name,
                        u.email as user_email,
                        u.company_name as company_name,
                        or_table.recipient_id,
                        or_table.status,
                        or_table.category,
                        or_table.conversation_id,
                        or_table.billable,
                        or_table.created_at,
                        or_table.updated_at,
                        or_table.delivered_time,
                        or_table.read_time,
                        or_table.display_phone_number,
                        or_table.error_code,
                        ec.description as error_message,
                        NULL as campaign_id,
                        NULL as campaign_name,
                        NULL as message_id
                    FROM out_reports or_table
                    INNER JOIN users u ON or_table.user_id = u.id
                    LEFT JOIN error_codes ec ON or_table.error_code::text = ec.code::text
                    WHERE or_table.created_at BETWEEN ? AND ?
                    {$outReportsUserFilter}
                    {$outReportsCategoryFilter}
                    {$outReportsStatusFilter}
                    {$outReportsRoleFilter}
                    {$outReportsSearchFilter}
                )";
        }

        // Add campaign_reports query if needed
        if ($includeCampaignReports) {
            $unionParts[] = "
                (
                    SELECT
                        cr.id,
                        'campaign_report' as type,
                        c.user_id,
                        u.name as user_name,
                        u.email as user_email,
                        u.company_name as company_name,
                        cr.mobile_number as recipient_id,
                        cr.status,
                        COALESCE(cr.template_category, 'Not specified') as category,
                        cr.conversation_id,
                        COALESCE(cr.billable, 0) as billable,
                        cr.created_at,
                        cr.updated_at,
                        NULL as delivered_time,
                        NULL as read_time,
                        u.whatsapp_number as display_phone_number,
                        cr.error_code,
                        ec.description as error_message,
                        cr.campaign_id,
                        c.name as campaign_name,
                        cr.message_id
                    FROM campaign_reports cr
                    INNER JOIN campaigns c ON cr.campaign_id = c.id
                    INNER JOIN users u ON c.user_id = u.id
                    LEFT JOIN error_codes ec ON cr.error_code::text = ec.code::text
                    WHERE cr.created_at BETWEEN ? AND ?
                    {$campaignReportsUserFilter}
                    {$campaignReportsCategoryFilter}
                    {$campaignReportsStatusFilter}
                    {$campaignReportsRoleFilter}
                    {$campaignReportsSearchFilter}
                )";
        }

        // Build final SQL
        $sql = implode(' UNION ALL ', $unionParts) . "
            ORDER BY updated_at DESC
            LIMIT 10000
        ";

        // Build parameters array
        $params = [];

        // Out reports parameters
        if ($includeOutReports) {
            $params[] = $dateRange['start']->format('Y-m-d H:i:s');
            $params[] = $dateRange['end']->format('Y-m-d H:i:s');

            if ($filters['user_id']) {
                $params[] = $filters['user_id'];
            }

            if ($filters['category']) {
                $params[] = $dbCategory;
            }

            if ($filters['status']) {
                $params[] = $filters['status'];
            }

            // Add role-based parameters for out_reports (skip for admin)
            if ($filters['role_type'] === 'reseller') {
                $params[] = $filters['reseller_id'];
            } elseif ($filters['role_type'] === 'user') {
                $params[] = $filters['allowed_user_ids'][0]; // User ID for their own data
                $params[] = $filters['user_whatsapp_number']; // WhatsApp number for display_phone_number match
            }
            // No parameters needed for admin role

            if ($filters['search']) {
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm; // recipient_id
                $params[] = $searchTerm; // display_phone_number
                $params[] = $searchTerm; // company_name
                $params[] = $searchTerm; // user name
                $params[] = $searchTerm; // user email
            }
        }

        // Campaign reports parameters
        if ($includeCampaignReports) {
            $params[] = $dateRange['start']->format('Y-m-d H:i:s');
            $params[] = $dateRange['end']->format('Y-m-d H:i:s');

            if ($filters['user_id']) {
                $params[] = $filters['user_id']; // for c.user_id = ?
                $params[] = $filters['user_id']; // for u.id = ?
            }

            if ($filters['category']) {
                $params[] = $dbCategory;
            }

            if ($filters['status']) {
                $params[] = $filters['status'];
            }

            // Add role-based parameters for campaign_reports (skip for admin)
            if ($filters['role_type'] === 'reseller') {
                $params[] = $filters['reseller_id'];
            } elseif ($filters['role_type'] === 'user') {
                $params[] = $filters['allowed_user_ids'][0]; // for c.user_id = ?
                $params[] = $filters['user_whatsapp_number']; // for u.whatsapp_number = ?
            }
            // No parameters needed for admin role

            if ($filters['search']) {
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm; // mobile_number
                $params[] = $searchTerm; // whatsapp_number
                $params[] = $searchTerm; // company_name
                $params[] = $searchTerm; // user name
                $params[] = $searchTerm; // user email
            }
        }

        // Debug SQL and parameters (temporary for troubleshooting)
        \Log::info('Global Reports SQL Debug', [
            'sql' => $sql,
            'params' => $params,
            'filters' => $filters,
            'date_range' => $dateRange,
            'includeOutReports' => $includeOutReports,
            'includeCampaignReports' => $includeCampaignReports,
            'unionParts_count' => count($unionParts)
        ]);

        $result = DB::select($sql, $params);

        // Additional debug: Log the actual result count
        \Log::info('Global Reports Query Result', [
            'result_count' => count($result),
            'first_result' => $result[0] ?? 'No results'
        ]);

        return $result;
    }

    /**
     * Generate summary from raw query results efficiently
     */
    private function generateFastSummary($reports, array $dateRange, array $filters)
    {
        $totalReports = count($reports);
        $outReportsCount = 0;
        $campaignReportsCount = 0;
        $statusBreakdown = [];
        $categoryBreakdown = [];
        $userBreakdown = [];

        foreach ($reports as $report) {
            // Count by type
            if ($report->type === 'out_report') {
                $outReportsCount++;
            } else {
                $campaignReportsCount++;
            }

            // Status breakdown
            $status = $report->status ?? 'unknown';
            $statusBreakdown[$status] = ($statusBreakdown[$status] ?? 0) + 1;

            // Category breakdown
            $category = $report->category ?? 'unknown';
            $categoryBreakdown[$category] = ($categoryBreakdown[$category] ?? 0) + 1;

            // User breakdown
            $userId = $report->user_id;
            if (!isset($userBreakdown[$userId])) {
                $userBreakdown[$userId] = [
                    'user_id' => $userId,
                    'user_name' => $report->user_name,
                    'company_name' => $report->company_name,
                    'count' => 0,
                    'out_reports' => 0,
                    'campaign_reports' => 0,
                ];
            }
            $userBreakdown[$userId]['count']++;
            if ($report->type === 'out_report') {
                $userBreakdown[$userId]['out_reports']++;
            } else {
                $userBreakdown[$userId]['campaign_reports']++;
            }
        }

        return [
            'total_reports' => $totalReports,
            'out_reports_count' => $outReportsCount,
            'campaign_reports_count' => $campaignReportsCount,
            'date_range' => [
                'start_date' => $dateRange['start']->format('Y-m-d H:i:s'),
                'end_date' => $dateRange['end']->format('Y-m-d H:i:s'),
                'type' => $dateRange['type'],
            ],
            'filters_applied' => [
                'user_id' => $filters['user_id'],
                'category' => $filters['category'],
                'report_type' => $filters['report_type'],
                'status' => $filters['status'],
                'search' => $filters['search'],
                'role_type' => $filters['role_type'] ?? 'none',
                'reseller_id' => $filters['reseller_id'] ?? null,
                'user_whatsapp_number' => $filters['user_whatsapp_number'] ?? null,
            ],
            'status_breakdown' => $statusBreakdown,
            'category_breakdown' => $categoryBreakdown,
            'user_breakdown' => array_values($userBreakdown),
        ];
    }    /**
     * Parse custom date format (DD/MM/YY or MM/DD/YY)
     */
    private function parseCustomDateFormat($dateString)
    {
        // Handle DD/MM/YY format (e.g., "08/07/25" should be July 8, 2025)
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2})$/', $dateString, $matches)) {
            $day = $matches[1];
            $month = $matches[2];
            $year = '20' . $matches[3]; // Convert YY to YYYY

            // Create date assuming DD/MM/YYYY format first
            try {
                return Carbon::createFromFormat('d/m/Y', "$day/$month/$year");
            } catch (\Exception $e) {
                // If DD/MM/YYYY fails, try MM/DD/YYYY
                try {
                    return Carbon::createFromFormat('m/d/Y', "$day/$month/$year");
                } catch (\Exception $e) {
                    // Fallback to Carbon::parse
                    return Carbon::parse($dateString);
                }
            }
        }

        // Handle other formats or fallback to Carbon::parse
        return Carbon::parse($dateString);
    }

    /**
     * Test method to debug date filtering issues
     */
    public function testDateFiltering(Request $request)
    {
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');

        // Parse dates using our custom method
        $parsedStart = $this->parseCustomDateFormat($startDate);
        $parsedEnd = $this->parseCustomDateFormat($endDate);

        // Direct database query to test
        $directQuery = DB::select("
            SELECT id, created_at, updated_at, status
            FROM out_reports
            WHERE created_at BETWEEN ? AND ?
            ORDER BY created_at DESC
            LIMIT 5
        ", [
            $parsedStart->startOfDay()->format('Y-m-d H:i:s'),
            $parsedEnd->endOfDay()->format('Y-m-d H:i:s')
        ]);

        // Also test with just date part
        $dateOnlyQuery = DB::select("
            SELECT id, created_at, updated_at, status
            FROM out_reports
            WHERE DATE(created_at) BETWEEN ? AND ?
            ORDER BY created_at DESC
            LIMIT 5
        ", [
            $parsedStart->format('Y-m-d'),
            $parsedEnd->format('Y-m-d')
        ]);

        return response()->json([
            'input' => [
                'startDate' => $startDate,
                'endDate' => $endDate
            ],
            'parsed' => [
                'start' => $parsedStart->startOfDay()->format('Y-m-d H:i:s'),
                'end' => $parsedEnd->endOfDay()->format('Y-m-d H:i:s'),
                'start_date_only' => $parsedStart->format('Y-m-d'),
                'end_date_only' => $parsedEnd->format('Y-m-d')
            ],
            'direct_query_results' => $directQuery,
            'date_only_query_results' => $dateOnlyQuery,
            'total_out_reports_count' => DB::table('out_reports')->count()
        ]);
    }
}
