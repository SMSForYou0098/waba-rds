<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\SupportAgent;
use App\Models\Report\AgentHasReport;
use App\Models\Report\Report;
use App\Models\User;
use App\Services\Email\RegisterMailService;
use Auth;
use Hash;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupportAgentController extends Controller
{
    protected $registerMailService;

    public function __construct(RegisterMailService $registerMailService)
    {
        $this->registerMailService = $registerMailService;
    }
  
    public function index()
    {
        $user = Auth::user();
        $supportAgents = SupportAgent::where('reporting_user', $user->id)
            ->with([
                'user' => function ($query) {
                    $query->select('id', 'name');
                },
            ])->get();

        // Loop through each support agent to get the active chat count
        foreach ($supportAgents as $agent) {
            $agent->active_chat_count = $this->getActiveChatCountByAgent($agent->user_id);
        }

        return response()->json([
            'status' => true,
            'data' => $supportAgents
        ]);
    }
  	private function getActiveChatCountByAgent($agentId)
    {
        // Get the last 24 hours' timestamp
        $last24Hours = now()->subDay();

        $waIds = AgentHasReport::where('agent_id', $agentId)
            ->pluck('wa_id'); 
        $activeChatCount = Report::whereIn('wa_id', $waIds)
            ->where('created_at', '>=', $last24Hours)
            ->distinct('wa_id')
            ->count();

        return $activeChatCount;
    }
    public function getSupportAgents()
    {
        $user = Auth::user();
        try {
            $supportAgents = User::where('reporting_user', $user->id)
                ->role('Support Agent')
                ->with('supportAgent')
                ->get();


            return response()->json([
                'status' => true,
                'data' => $supportAgents
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve support agents',
                'error' => $e->getMessage()
            ], 500);
        }
    }
  
    public function create(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email|unique:support_agents,email',
            'phone' => 'required|string|unique:users,phone_number|unique:support_agents,phone',
            'password' => 'required|string',
            'user_id' => 'required|integer',
            'status' => 'required|string',
            'nickname' => 'nullable|string',
            'last_online' => 'nullable',
            'joining_date' => 'nullable|date',
            'working_days' => 'nullable|string',
            'working_start' => 'nullable',
            'working_end' => 'nullable',
        ]);
        // Start database transaction
        DB::beginTransaction();
	
        try {
            // Create User
            $user = new User();
            $user->name = $request->name;
            $user->email = $request->email;
            $user->reporting_user = $request->user_id;
            $user->phone_number = $request->phone;
          	$user->status = $request->status;
          	$user->email_alerts = 'true';
            $user->password = Hash::make($request->password);
            $user->save();

            // Assign Support Agent role to the user using Spatie
            $supportAgentRole = Role::firstOrCreate(['name' => 'Support Agent', 'guard_name' => 'api']);
            $user->assignRole($supportAgentRole);

            // Create SupportAgent
          	$keywordAsString = json_encode($request->keyword);
            $supportAgent = new SupportAgent();
          	$supportAgent->keyword = $keywordAsString;
            $supportAgent->user_id = $user->id;
            $supportAgent->reporting_user = $request->user_id;
            $supportAgent->name = $request->name;
            $supportAgent->email = $request->email;
            $supportAgent->nickname = $request->nickname;
            $supportAgent->phone = $request->phone;
            $supportAgent->last_online = $request->last_online;
            $supportAgent->password = Hash::make($request->password);
            $supportAgent->joining_date = $request->joining_date;
            $supportAgent->working_days = $request->working_days;
            $supportAgent->working_start = $request->working_start;
            $supportAgent->working_end = $request->working_end;
            $supportAgent->status = $request->status ?? 'active';
            $supportAgent->save();

            // Commit the transaction
            DB::commit();
			$verificationResponse = $this->triggerEmailVerification($user->id);
            return response()->json([
                'status' => true,
                'message' => 'Support agent created successfully',
                'data' => [
                    'user' => $user,
                    'support_agent' => $supportAgent,
                    'verification' => $verificationResponse
                ]
            ]);
        } catch (\Exception $e) {
            // Rollback the transaction in case of error
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to create support agent',
                'error' => $e->getMessage()
            ], 500);
        }
    }
  	private function triggerEmailVerification($userId)
    {
        try {
            // Prepare the request
            $request = Request::create(
                route('register.mail'), // Use the named route
                'POST',
                ['id' => $userId]
            );

            // Bypass any authentication middleware if needed
            $request->headers->set('Authorization', request()->header('Authorization'));

            // Dispatch the request to the route
            $response = app()->handle($request);

            // Parse the response
            $responseData = json_decode($response->getContent(), true);

            // Check if the request was successful
            if ($response->getStatusCode() == 200) {
                return [
                    'status' => true,
                    'message' => 'Verification email sent',
                    'details' => $responseData
                ];
            } else {
                return [
                    'status' => false,
                    'message' => 'Failed to send verification email',
                    'details' => $responseData
                ];
            }
        } catch (\Exception $e) {
            // Log the error
            \Log::error('Email verification failed: ' . $e->getMessage());

            return [
                'status' => false,
                'message' => 'Error in email verification process',
                'error' => $e->getMessage()
            ];
        }
    }
  
	public function update(Request $request, $id)
    {
        try {
            $supportAgent = SupportAgent::findOrFail($id);
            $user = User::findOrFail($supportAgent->user_id);

            $validator = \Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => [
                    'sometimes',
                    'email',
                    // Ignore the current user's email when checking for uniqueness
                    Rule::unique('users', 'email')->ignore($user->id)
                ],
                'phone' => [
                    'sometimes',
                    'string',
                    // Ensure phone is unique in support_agents table, excluding current agent
                    Rule::unique('support_agents', 'phone')->ignore($supportAgent->id)
                ],
                'nickname' => 'sometimes|string|max:100',
                'working_days' => 'sometimes|string',
                'working_start' => 'sometimes|date_format:H:i',
                'working_end' => 'sometimes|date_format:H:i',
                'status' => 'sometimes|in:active,inactive,suspended'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 400);
            }

            // Start database transaction
            DB::beginTransaction();


			
            // Update user details
            if ($request->has('name')) {
                $user->name = $request->name;
            }
            if ($request->has('email')) {
                $user->email = $request->email;
            }
            if ($request->has('phone')) {
                $user->phone_number = $request->phone;
            }
            if ($request->has('status')) {
                $user->status = $request->status;
            }
          	if ($request->has('password')) {
                $request->validate([
                    'password' => 'min:8'
                ]);
                $user->password = Hash::make($request->password);
            }
            $user->save();

            // Update support agent details
            if ($request->has('name')) {
                $supportAgent->name = $request->name;
            }
            if ($request->has('email')) {
                $supportAgent->email = $request->email;
            }
            if ($request->has('nickname')) {
                $supportAgent->nickname = $request->nickname;
            }
            if ($request->has('phone')) {
                $supportAgent->phone = $request->phone;
            }
            if ($request->has('joining_date')) {
                $supportAgent->joining_date = $request->joining_date;
            }
            if ($request->has('working_days')) {
                $supportAgent->working_days = $request->working_days;
            }
            if ($request->has('working_start')) {
                $supportAgent->working_start = $request->working_start;
            }
            if ($request->has('working_end')) {
                $supportAgent->working_end = $request->working_end;
            }
            if ($request->has('status')) {
                $supportAgent->status = $request->status;
            }
          	if ($request->has('keyword')) {
             	$keywordAsString = json_encode($request->keyword);
                $supportAgent->keyword = $keywordAsString;
            }
            // Save the updated details
            $supportAgent->save();

            DB::commit();
			$verificationResponse = $this->registerMailService->sendRegisterMail($user->id);
            return response()->json([
                'status' => true,
                'message' => 'Support agent updated successfully',
                'data' => [
                    'user' => $user,
                    'support_agent' => $supportAgent
                ]
            ]);
        } catch (\Exception $e) {
            // Rollback the transaction in case of error
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to update support agent',
                'error' => $e->getMessage()
            ], 500);
        }
    }
  
  	public function toggleOnlineStatus(Request $request, $id)
    {
        try {
            $supportAgent = SupportAgent::where('user_id',$id)->firstOrFail();

            // Check the request for the desired status
            if ($request->status === 'online') {
                $supportAgent->online = true; // Set online status
                $supportAgent->last_online = null; // Clear last online time if setting online
                $message = 'Support agent is now online';
            } elseif ($request->status === 'offline') {
                $supportAgent->online = false; // Set offline status
                $supportAgent->last_online = now(); // Store current time as last online
                $message = 'Support agent is now offline';
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid status provided. Use "online" or "offline".'
                ], 400);
            }

            $supportAgent->save();

            return response()->json([
                'status' => true,
               // 'message' => $message,
               // 'data' => $supportAgent
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to toggle support agent status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
  
    public function updateReport(Request $request, $id)
    {
        try {
            $report = AgentHasReport::updateOrCreate(
                ['id' => $id],
                [
                    'agent_id' => $request->agent_id,
                    'display_phone_number' => $request->display_phone_number,
                    'wa_id' => $request->wa_id,
                ]
            );
            $message = $report->wasRecentlyCreated ? 'Report created successfully' : 'Report updated successfully';

            return response()->json([
                'status' => true,
                'message' => $message,
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update or create report',
                'error' => $e->getMessage()
            ], 500);
        }
    }
  
    public function destroy($id)
    {
        try {
            $supportAgent = SupportAgent::findOrFail($id);
            $user = User::findOrFail($supportAgent->user_id);

            // Start database transaction
            DB::beginTransaction();

            // Delete the support agent
            $supportAgent->delete();

            // Delete the user
            $user->delete();
          	// also set null where agent_id is used in ChatHistory
            ChatHistory::where('agent_id', $user->id)->update(['agent_id' => null]);
            // also delete related reports
            AgentHasReport::where('agent_id', $user->id)->delete();
            // Commit the transaction
            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Support agent deleted successfully'
            ]);
        } catch (\Exception $e) {
            // Rollback the transaction in case of error
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete support agent',
                'error' => $e->getMessage()
            ], 500);
        }
    }
  
}
