<?php

namespace App\Services\Email;

use App\Models\User;
use Carbon\Carbon;

class EmailVerificationService
{
    public function verifyEmail($id)
    {
        $user = User::find($id);
        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
                'status' => 404
            ];
        }
        if ($user->hasVerifiedEmail()) {
            return [
                'success' => true,
                'message' => 'User is already verified',
                'status' => 200
            ];
        }
        $user->email_verified_at = Carbon::now();
        $user->save();
        return [
            'success' => true,
            'message' => 'User email verified successfully',
            'status' => 200
        ];
    }
}
