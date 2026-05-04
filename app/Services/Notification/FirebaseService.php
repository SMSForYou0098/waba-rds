<?php

namespace App\Services\Notification;

use App\Models\Notification\FcmToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Google\Client;

class FirebaseService
{
    protected $projectId;
    protected $accessToken;

    public function __construct()
    {
        $configPath = config('firebase.projects.app.credentials');
        
        if ($configPath && file_exists($configPath)) {
            $credentialsPath = $configPath;
        } elseif ($configPath && file_exists(base_path($configPath))) {
            $credentialsPath = base_path($configPath);
        } elseif ($configPath && file_exists(storage_path($configPath))) {
            $credentialsPath = storage_path($configPath);
        } else {
            $credentialsPath = storage_path('app/firebase/service-account.json');
        }

        $credentialsFile = json_decode(file_get_contents($credentialsPath), true);
        $this->projectId = $credentialsFile['project_id'];
        $this->getAccessToken($credentialsPath);
    }

    private function getAccessToken($credentialsPath)
    {
        try {
            $client = new Client();
            $client->setAuthConfig($credentialsPath);
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            $this->accessToken = $client->fetchAccessTokenWithAssertion()['access_token'];
        } catch (\Exception $e) {
            Log::error('Firebase authentication error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send data-only notification to a single device
     */
    public function sendToDevice($token, $title, $body, $url, $imageUrl = '', $data = [])
    {
        if (!is_array($data)) {
            $data = [];
        }

        // Only data payload - no notification payload
        $formattedData = [
            'title' => $title,
            'body' => $body,
            'click_action' => $url,
        ];
        
        foreach ($data as $key => $value) {
            if (!isset($formattedData[$key])) {
                $formattedData[$key] = (string) $value;
            }
        }

        $message = [
            'message' => [
                'token' => $token,
                'data' => $formattedData
            ]
        ];

        return $this->sendNotification($message);
    }

    public function sendToMultipleDevices($tokens, $title, $body, $url, $imageUrl = '', $data = [])
    {
        if (empty($tokens)) {
            return ['error' => 'No tokens provided'];
        }

        $results = [];
        foreach ($tokens as $token) {
            $result = $this->sendToDevice($token, $title, $body, $url, $imageUrl, $data);
            $results[] = $result;
        }
        
        return $results;
    }

    public function sendToAllDevices($title, $body, $url, $imageUrl = '', $data = [])
    {
        $tokens = FcmToken::pluck('token')->toArray();
        return $this->sendToMultipleDevices($tokens, $title, $body, $url, $imageUrl, $data);
    }

    public function sendToUser($userId, $title, $body, $imageUrl = '', $data = [],$url)
    {
        $tokens = FcmToken::where('user_id', $userId)->pluck('token')->toArray();
        return $this->sendToMultipleDevices($tokens, $title, $body, $url, $imageUrl, $data);
    }

    private function sendNotification($message)
    {
        try {
            $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $message);

            if ($response->failed()) {
                Log::error('Firebase notification error: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Firebase notification exception: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}