<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\Mail;
use App\Mail\SendEmail;

class EmailService
{
    public function sendEmail($email, $subject, $body)
    {
        try {
            Mail::to($email)->send(new SendEmail($subject, $body));
            return [
                'success' => true,
                'message' => 'Email sent successfully.'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to send email.',
                'error' => $e->getMessage()
            ];
        }
    }
}
