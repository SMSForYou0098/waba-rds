<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Settings\UserConfig;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class UserConfigController extends Controller
{

    public function index($id)
    {
        $history = UserConfig::where('user_id', $id)
            ->with([
                'user' => function ($query) {
                    $query->select('id', 'whatsapp_number');
                }
            ])->get();

        return response()->json(['history' => $history]);
    }
    public function create(Request $request)
    {
        try {
            $user = User::findOrFail($request->user_id);
            if ($user) {
                $user->whatsapp_number = $request->whatsapp_number;
                $user->save();
            }
            $existingRecord = UserConfig::where('user_id', $request->user_id)->first();
            if ($existingRecord) {
                $existingRecord->app_id = $request->app_id;
                $existingRecord->whatsapp_business_account_id = $request->whatsapp_business_account_id;
                $existingRecord->business_account_id = $request->business_account_id;
                $existingRecord->meta_access_token = $request->meta_access_token;
                $existingRecord->whatsapp_phone_id = $request->whatsapp_phone_id;
                $existingRecord->save();

                return response()->json(['message' => 'credential updated successfully']);
            } else {
                // Create a new record
                $newRecord = new UserConfig();
                $newRecord->user_id = $request->user_id;
                $newRecord->app_id = $request->app_id;
                $newRecord->whatsapp_business_account_id = $request->whatsapp_business_account_id;
                $newRecord->business_account_id = $request->business_account_id;
                $newRecord->meta_access_token = $request->meta_access_token;
                $newRecord->whatsapp_phone_id = $request->whatsapp_phone_id;
                $newRecord->save();

                return response()->json(['status' => 'true', 'message' => 'credential created successfully']);

            }
        } catch (QueryException $exception) {
            // Handle database query exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        } catch (\Exception $exception) {
            // Handle other exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        }
    }
  
  	public function HandleChatBotTime(Request $request, $id)
    {
        try {
            $userConfig = UserConfig::where('user_id', $id)->first();
            if ($userConfig) {
                $userConfig->chatbot_timer = $request->chatbot_timer;
                $userConfig->timer_start_time = $request->timer_start_time;
                $userConfig->timer_end_time = $request->timer_end_time;
                $userConfig->including = $request->including;
                $userConfig->default_timing_res_type = $request->default_timing_res_type;
                $userConfig->default_timing_template = $request->default_timing_template;
                $userConfig->default_timing_res = $request->default_timing_res;

                $userConfig->save();
                 return response()->json(['status' => true, 'message' => 'Chatbot configuration updated successfully']);
            } else {
                return response()->json(['status' => false, 'message' => 'User  configuration not found'], 404);
            }
        } catch (QueryException $exception) {
            return response()->json(['status' => false, 'message' => 'Error: ' . $exception->getMessage()], 500);
        } catch (\Exception $exception) {
            return response()->json(['status' => false, 'message' => 'Error: ' . $exception->getMessage()], 500);
        }
    }
    public function getChatBotSetting($id)
    {
        try {
            $userConfig = UserConfig::where('user_id', $id)->first();
            if ($userConfig) {
                return response()->json(['status' => true, 'data' => $userConfig]);
            } else {
                return response()->json(['status' => false, 'message' => 'User  configuration not found'], 404);
            }
        } catch (QueryException $exception) {
            return response()->json(['status' => false, 'message' => 'Error: ' . $exception->getMessage()], 500);
        } catch (\Exception $exception) {
            return response()->json(['status' => false, 'message' => 'Error: ' . $exception->getMessage()], 500);
        }
    }
  
}
