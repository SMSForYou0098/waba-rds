<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\Campaign\CampaignReport;
use App\Models\User;
use App\Models\Campaign\Campaign;
use App\Models\Billing\Balance;
use App\Models\Contact\Group;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class ExportHandleController extends Controller
{
    protected $today;

    public function __construct()
    {
        $this->today = Carbon::today()->toDateString();
    }
    public function ExportReport(Request $request, $id)
    {
        $today = $this->today;
        $startDate = Carbon::createFromFormat('d/m/y', $request->startDate)->startOfDay();
        $endDate = Carbon::createFromFormat('d/m/y', $request->endDate)->endOfDay();

        $report = User::where('id', $id)
            ->with([
                'reports' => function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('created_at', [$startDate, $endDate]);
                }
            ])
            ->first();
        $reportData = [
            'reports' => $report->reports->map(function ($item) use ($report) {
                return [
                    'Name' => $report->name,
                    'From' => $item->wa_id,
                    'Name From' => $item->profile_name,
                    'To' => $item->display_phone_number,
                    //'Message' => $item->profile_name,
                    'Date' => Carbon::parse($item->created_at)->format('d-m-Y | h:i:s A'),
                ];
            })
        ];
        return response()->json(['data' => $reportData['reports']], 200);
        // $export = new ReportExport($reportData['reports']);

        // return Excel::download($export, 'user_report.xlsx');
    }
    public function ExportOutReport($id)
    {
        $today = $this->today;
        $report = User::where('id', $id)
            ->with([
                'outReports' => function ($query) use ($today) {
                    // $query->whereDate('created_at', $today);
                }
            ])
            ->first();
        $reportData = [
            'reports' => $report->outReports->map(function ($item) use ($report) {
                return [
                    'Name' => $report->name,
                    'From' => $item->display_phone_number,
                    'To' => $item->recipient_id,
                    'Category' => $item->category,
                    'Message' => $item->profile_name,
                    'Status' => $item->status,
                    'Date' => Carbon::parse($item->created_at)->format('d-m-Y | h:i:s A'),
                ];
            })
        ];
        return response()->json(['data' => $reportData['reports']], 200);
    }
    public function ExportCostReport($id)
    {
        $report = User::where('id', $id)->with('userConfig', 'pricingModel')->first();
        $client = new Client();
        $wapId = $report->userConfig->whatsapp_business_account_id;
        $waToken = $report->userConfig->meta_access_token;
        $MP = $report->pricingModel->marketing_price;
        $UP = $report->pricingModel->utility_price;
        $timeStamp = now()->timestamp;
        $apiUrl = str_replace(
            [':wapId:', ':realtime_unix:', ':waToken:'],
            [$wapId, $timeStamp, $waToken],
            env('WA_API_ANALYTICS')
        );
        try {
            $response = $client->get($apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $waToken,
                    'Accept' => 'application/json',
                ]
            ]);

            $data = json_decode($response->getBody(), true)['conversation_analytics']['data'][0]['data_points'];

            usort($data, function ($a, $b) {
                return $b['start'] - $a['start'];
            });
            $reportData = [
                'reports' => collect($data)->map(function ($item) use ($report, $MP, $UP) {
                    return [
                        'Name' => $report->name,
                        'Number' => $item['phone_number'],
                        'Conversation Category' => $item['conversation_category'],
                        'Conversation Type' => $item['conversation_type'],
                        'Total Messages' => $item['conversation'],
                        'Cost' => ($item['conversation_category'] === 'MARKETING')
                            ? '₹' . ($MP * $item['conversation'])
                            : '₹' . ($UP * $item['conversation']),
                        'Date' => Carbon::createFromTimestamp($item['start'])->format('d/m/y'),
                    ];
                })
            ];
            return response()->json(['data' => $reportData['reports']], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    public function ExportTemplateReport($id)
    {
        $client = new Client();
        $report = User::where('id', $id)->with('userConfig')->first();
        $wapId = $report->userConfig->whatsapp_business_account_id;
        $waToken = $report->userConfig->meta_access_token;
        $templates = env('WA_API_TEMPLATES');
        $templatesApi = str_replace(':whatsapp_business_account_id:', $wapId, $templates);
        $templatesApi = str_replace(':waToken:', $waToken, $templatesApi);
        try {
            $response = $client->get($templatesApi, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $waToken,
                    'Accept' => 'application/json',
                ]
            ]);

            $data = json_decode($response->getBody(), true)['data'];
            $reportData = [
                'reports' => collect($data)->map(function ($item) use ($report) {
                    return [
                        'Name' => $item['name'],
                        'Language' => $item['language'],
                        'Template Type' => $item['category'],
                        'Status' => $item['status'],
                    ];
                })
            ];
            return response()->json(['data' => $reportData['reports']], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function ExportCampaignReport($id)
    {
        $report = Campaign::where('user_id', $id)->withCount('campaignReports')->whereDate('created_at', $this->today)->get();
        $reportData = [
            'reports' => $report->map(function ($item) {
                return [
                    'Name' => $item->name,
                    'Total Numbers' => $item->campaign_reports_count,
                    'Template Name' => $item->template_name,
                    'Date' => Carbon::parse($item->created_at)->format('d-m-Y | h:i:s A'),

                ];
            })
        ];
        return response()->json(['data' => $reportData['reports']], 200);
    }
    public function ExportCampaignContact($id)
    {
        $report = CampaignReport::where('campaign_id', $id)
            ->with('campaign:id,name', 'errorCode')
            ->get();
        // return response()->json(['data' => $report], 200);
        $reportData = [
            'reports' => $report->map(function ($item) {
                return [
                    'Campaign Name' => $item->campaign->name,
                    'Number' => $item->mobile_number,
                    'Status' => $item->status,
                    'Status Description' => $item?->error_code ? $item->errorCode?->description : $item->status,
                    'Date' => Carbon::parse($item->updated_at)->format('d-m-Y | h:i:s A'),

                ];
            })
        ];
        return response()->json(['data' => $reportData['reports']], 200);
    }
    public function ExportGroupReport($id)
    {
        $report = Group::where('user_id', $id)
            ->withCount('contacts')
            ->whereDate('created_at', $this->today)
            ->latest()->get();
        // return response()->json(['data' => $report], 200);
        $reportData = [
            'reports' => $report->map(function ($item) {
                return [
                    'Name' => $item->name,
                    'Description' => $item->description,
                    'Total Contacts' => $item->contacts_count,
                    'Date' => Carbon::parse($item->created_at)->format('d-m-Y | h:i:s A'),

                ];
            })
        ];
        return response()->json(['data' => $reportData['reports']], 200);
    }
    //kinjal
    // public function ExportGroupContactReport($id)
    // {
    //     $today = $this->today;
    //     $report = Group::where('user_id', $id)
    //         ->with([
    //             'contacts' => function ($query) use ($today) {
    //                 $query->whereDate('created_at', $today);
    //             }
    //         ])
    //         ->latest()->first();
    //     $reportData = [
    //         'reports' => $report->contacts->map(function ($item) use ($report) {
    //             return [
    //                 'Group Name' => $report->name,
    //                 'Contact Name' => $item->name,
    //                 'Location' => $item->location,
    //                 'Number' => $item->number,
    //                 'Date' => Carbon::parse($item->created_at)->format('d-m-Y | h:i:s A'),

    //             ];
    //         })
    //     ];
    //     return response()->json(['data' => $reportData['reports']], 200);
    // }

    public function ExportGroupContactReport($id)
    {
        $report = Group::where('id', $id) // 'group_id' ni jagya e 'id' lakhyu
            ->with(['contacts'])
            ->latest()
            ->first();

        if (!$report) {
            return response()->json(['error' => 'Group not found'], 404);
        }

        $reportData = [
            'reports' => $report->contacts->map(function ($item) use ($report) {
                return [
                    'Group Name' => $report->name,
                    'Contact Name' => $item->name,
                    'Location' => $item->location,
                    'Number' => $item->number,
                    'Date' => Carbon::parse($item->created_at)->format('d-m-Y | h:i:s A'),
                ];
            })
        ];

        return response()->json(['data' => $reportData['reports']], 200);
    }


    public function ExportUsers($id)
    {
        $report = User::with(['balance', 'pricingModel', 'reportingUser'])->whereDate('created_at', $this->today)->get();
        $report->each(function ($user) {
            $totalCredits = $user->balance()->latest()->first();
            $user->latest_balance = optional($totalCredits)->total_credits;
            $user->role_name = $user->roles[0]->name;
            // $user->pricing = $user->pricingModel()->latest()->first();
            $reporting = $user->reportingUser()->first();
            $user->rp = optional($reporting)->name;
            unset($user->balance);
            unset($user->roles);
            unset($user->pricingModel);
            unset($user->reportingUser);
        });
        //return response()->json(['data' => $report], 200);
        $reportData = [
            'reports' => $report->map(function ($item) {
                return [
                    'Name' => $item->name,
                    'Email' => $item->email,
                    'Phone Number' => $item->phone_number,
                    'Whatsapp Number' => $item->whatsapp_number,
                    'Role' => $item->role_name,
                    'Account Manager' => '₹' . $item->rp,
                    'Available Credit' => $item->latest_balance,
                    'Date' => Carbon::parse($item->created_at)->format('d-m-Y | h:i:s A'),

                ];
            })
        ];
        return response()->json(['data' => $reportData['reports']], 200);
    }
    public function ExportCreditHistory($id)
    {
        // return response()->json(['report' => Auth::user()]);
        if (Auth::check() && Auth::user()->hasRole('Admin')) {
            $today = Carbon::today()->toDateString();
            $report = Balance::where('auto_deduction', null)->whereDate('created_at', $this->today)->with([
                'user' => function ($query) {
                    $query->select('id', 'name');
                }
            ])->get();
            $reportData = [
                'reports' => $report->map(function ($item) use ($report) {
                    return [
                        'Name' => $item->user->name,
                        'Credit' => $item->new_credit,
                        'Transaction Type' => $item->manual_deduction != null ? 'Debit' : 'Credit',
                        'Date' => Carbon::parse($item->created_at)->format('d-m-Y | h:i:s A'),

                    ];
                })
            ];
            return response()->json(['data' => $reportData['reports']], 200);
        }
    }
}
