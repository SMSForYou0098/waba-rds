<?php

namespace App\Services\Email;

use App\Models\User;
use GuzzleHttp\Client;

class RegisterMailService
{
    public function sendRegisterMail($userId)
    {
        try {
            $user = User::findOrFail($userId);
            $verificationUrl = route('verification.verify', [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification())
            ]);

            $emailData = [
                'template' => 'User Verification Mail',
                'email' => $user->email,
                'username' => $user->name,
                'verification_url' => $verificationUrl
            ];

            $client = new Client();
            $response = $client->post(route('send-email', ['id' => $user->id]), [
                'form_params' => $emailData
            ]);

            if ($response->getStatusCode() == 200) {
                $data = json_decode($response->getBody(), true);
                return [
                    'success' => true,
                    'data' => $data
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Request failed with status ' . $response->getStatusCode()
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'An error occurred: ' . $e->getMessage()
            ];
        }
    }
}
