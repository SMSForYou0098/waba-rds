<?php

namespace App\Services\User;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class UserOtpService
{
    /**
     * @return array{message?: string, error?: string, http_status: int}
     */
    public function send(Request $request): array
    {
        $number = $request->number;
        $smsApiKey = $request->smsApiKey;
        $smsSenderId = $request->smsSenderId;
        $otp = rand(100000, 999999);

        Cache::put($number, $otp, 300);

        $response = $this->sendOtpSms($number, $otp, $smsApiKey, $smsSenderId);

        if ($response->successful()) {
            return ['message' => 'OTP sent successfully', 'http_status' => 200];
        }

        return ['error' => 'Failed to send OTP', 'http_status' => 500];
    }

    /**
     * @return array{message?: string, error?: string, http_status: int}
     */
    public function verify(Request $request): array
    {
        $number = $request->input('number');
        $otp = $request->input('otp');
        $cachedOtp = Cache::get($number);

        if ($cachedOtp && $cachedOtp == $otp) {
            Cache::forget($number);

            return ['message' => 'OTP verified successfully', 'http_status' => 200];
        }

        return ['error' => 'Invalid OTP or OTP expired', 'http_status' => 401];
    }

    private function sendOtpSms(string $number, int $otp, string $smsApiKey, string $smsSenderId)
    {
        $message = sprintf(
            'OTP for login is %d and is valid for 5 minutes.(Generated at %s)',
            $otp,
            now()->format('m/d/Y H:i:s')
        );

        return Http::get('https://login.smsforyou.biz/V2/http-api.php', [
            'apikey' => $smsApiKey,
            'senderid' => $smsSenderId,
            'number' => $number,
            'message' => $message,
            'format' => 'json',
        ]);
    }
}
