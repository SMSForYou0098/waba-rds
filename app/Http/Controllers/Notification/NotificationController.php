<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Notification\FcmToken;
use App\Models\User;
use App\Services\Notification\FirebaseService;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    protected $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    public function saveFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
            'user_id' => 'required|integer',
            'device_id' => 'required|string',
            'device_type' => 'string|in:web,mobile,desktop',
            'browser_name' => 'nullable|string',
            'browser_version' => 'nullable|string',
            'os_name' => 'nullable|string',
            'device_info' => 'nullable|json' // For additional device details
        ]);

        $fcmToken = FcmToken::updateOrCreate(
            [
                'user_id' => $request->user_id,
                'device_id' => $request->device_id
            ],
            [
                'token' => $request->fcm_token,
                'device_type' => $request->device_type ?? 'web',
                'browser_name' => $request->browser_name,
                'browser_version' => $request->browser_version,
                'os_name' => $request->os_name,
                'device_info' => $request->device_info ? json_encode($request->device_info) : null,
                'updated_at' => now()
            ]
        );

        return response()->json([
            'message' => 'FCM token saved successfully',
            'device_id' => $fcmToken->device_id,
            'browser_name' => $fcmToken->browser_name,
            'token_id' => $fcmToken->id
        ]);
    }

    // Optional: Get all active tokens for a user (useful for sending notifications)
    public function getUserTokens($userId)
    {
        return FcmToken::where('user_id', $userId)
            ->where('updated_at', '>=', now()->subDays(30)) // Only tokens updated in last 30 days
            ->select('token', 'device_id', 'device_type', 'updated_at')
            ->get()
            ->toArray();
    }

    // Optional: Clean up old/inactive tokens
    public function cleanupOldTokens()
    {
        $deletedCount = FcmToken::where('updated_at', '<', now()->subDays(60))
            ->delete();

        return response()->json([
            'message' => 'Cleanup completed',
            'deleted_tokens' => $deletedCount
        ]);
    }

    // Optional: Remove specific device token
    public function removeDeviceToken(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'device_id' => 'required|string'
        ]);

        $deleted = FcmToken::where('user_id', $request->user_id)
            ->where('device_id', $request->device_id)
            ->delete();

        if ($deleted) {
            return response()->json(['message' => 'Device token removed successfully']);
        } else {
            return response()->json(['message' => 'Device token not found'], 404);
        }
    }

    // Optional: Get user's device info
    public function getUserDevices($userId)
    {
        $devices = FcmToken::where('user_id', $userId)
            ->select(
                'device_id',
                'device_type',
                'browser_name',
                'browser_version',
                'os_name',
                'device_info',
                'updated_at',
                'created_at'
            )
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($device) {
                $deviceInfo = $device->device_info ? json_decode($device->device_info, true) : [];

                return [
                    'device_id' => $device->device_id,
                    'device_type' => $device->device_type,
                    'browser_name' => $device->browser_name ?? 'Unknown',
                    'browser_version' => $device->browser_version,
                    'os_name' => $device->os_name ?? 'Unknown',
                    'display_name' => $this->generateDeviceDisplayName($device),
                    'screen_resolution' => $deviceInfo['screen'] ?? null,
                    'last_active' => $device->updated_at->diffForHumans(),
                    'first_registered' => $device->created_at->diffForHumans(),
                    'is_active' => $device->updated_at >= now()->subDays(7)
                ];
            });

        return response()->json([
            'user_id' => $userId,
            'devices' => $devices,
            'total_devices' => $devices->count(),
            'active_devices' => $devices->where('is_active', true)->count()
        ]);
    }
    public function getUserTokensWithInfo($userId)
    {
        return FcmToken::where('user_id', $userId)
            ->where('updated_at', '>=', now()->subDays(30))
            ->select('token', 'device_id', 'browser_name', 'os_name', 'device_type')
            ->get()
            ->map(function ($token) {
                return [
                    'token' => $token->token,
                    'device_info' => [
                        'device_id' => $token->device_id,
                        'browser' => $token->browser_name,
                        'os' => $token->os_name,
                        'type' => $token->device_type
                    ]
                ];
            })
            ->toArray();
    }
    private function generateDeviceDisplayName($device)
    {
        $browserName = $device->browser_name ?? 'Unknown Browser';
        $osName = $device->os_name ?? 'Unknown OS';

        // Create a friendly display name
        return "{$browserName} on {$osName}";
    }

 	public function sendNotification(Request $request)
    {
      Log::info('Send Notification Request:', $request->all());
        $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'user_id' => 'nullable|integer',
            'data' => 'nullable|array',
            'display_phone_number' => 'nullable|integer',
        ]);

        $title = $request->title;
        $body = $request->body;
        $data = [];
        $displayPhoneNumber = $request->display_phone_number;
        if ($displayPhoneNumber) {
            $user_id = User::where('whatsapp_number', $displayPhoneNumber)->value('id');
        } else {
            $user_id = $request->user_id;
        }

		$url = 'https://beta.smsforyou.biz/chats/'.$request->wa_id;
        if ($user_id) {
            $result = $this->firebaseService->sendToUser($user_id, $title, $body, '', $data, $url);
        } else {
            return response()->json(['error' => 'No target specified'], 400);
        }

        return response()->json($result);
    }
}
