<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template\EmailTemplate;
use App\Models\User;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\SendEmail;

class EmailTemplateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($id)
    {
        $emailTemplate = EmailTemplate::where('user_id', $id)->get();
        return response()->json([
            'templates' => $emailTemplate,
            'status' => true
        ], 200);
    }

    public function send(Request $request, $id)
    {

        $template = $request->template;
        $userEmail = $request->email;
        $emailTemplate = EmailTemplate::where('template_id', $request->template)->firstOrFail();
        $user = null;
        if ($emailTemplate) {
            $user = User::where('email', $userEmail)->first();
            // there is key email_alerts , if is it is true then send email
            if ($user && (!$user->email_alerts || $user->email_alerts === false)) {
                return response()->json([
                    'message' => 'Email alerts are disabled for this user.',
                    'status' => false
                ], 400);
            }
        }
        if ($template === 'Low Credit Alert') {
            return $this->sendBalanceAlertMail($request, $id, $emailTemplate, $user);
        } else if ($template === 'Login Otp Template') {
            return $this->sendLoginAlertMail($request, $userEmail, $emailTemplate);
        } else if ($template === 'API Key Generate') {
            return $this->sendKeyGenerateAlertMail($request, $userEmail, $emailTemplate);
        } else if ($template === 'Forgot Password') {
            return $this->sendForgotPasswordLink($request, $userEmail, $emailTemplate, $user);
        } else if ($template === 'Password Changed') {
            return $this->sendPasswordChangeAlert($request, $userEmail, $emailTemplate, $user);
        } else if ($template === 'Schedule Campaign Execute') {
            return $this->sendScheduleCampaignConfirmation($request, $userEmail, $emailTemplate);
        } else if ($template === 'Login Security Status Update') {
            return $this->sendLoginSecurity($request, $userEmail, $emailTemplate);
        } else if ($template === 'User Verification Mail') {
            return $this->sendVerificationMail($request, $userEmail, $emailTemplate, $request->username);
        }

    }
    private function sendVerificationMail($request, $userEmail, $emailTemplate, $username)
    {
        $body = $emailTemplate->body;
        $subject = $emailTemplate->subject;
        $verificationUrl = $request->verification_url;
        $body = str_replace(
            [':URL', ':USER'], // Search for both placeholders
            ['<a href="' . $verificationUrl . '">Click Here</a>', $username], // Replace with corresponding values
            $body
        );
        return $this->SendEmail($userEmail, $subject, $body);
    }
    private function sendLoginSecurity($email, $emailTemplate)
    {

        $body = $emailTemplate->body;
        //$body = str_replace('#otp', $request->otp, $body);
        $subject = $emailTemplate->subject;
        return $this->SendEmail($email, $subject, $body);
    }
    private function sendTwoFectorAlertIP($request, $email, $emailTemplate)
    {

        $body = $emailTemplate->body;
        //$body = str_replace('#otp', $request->otp, $body);
        $subject = $emailTemplate->subject;
        return $this->SendEmail($email, $subject, $body);
    }
    private function sendScheduleCampaignConfirmation($request, $email, $emailTemplate)
    {

        $body = $emailTemplate->body;
        $body = str_replace('[Campaign Name]', $request->campaign_name, $body);
        $body = str_replace('[Date and Time]', $request->date, $body);
        $subject = $emailTemplate->subject;
        return $this->SendEmail($email, $subject, $body);
    }
    private function sendPasswordChangeAlert($request, $email, $emailTemplate, $user)
    {

        $body = $emailTemplate->body;
        $body = str_replace('[User Name]', $user->company_name, $body);
        $subject = $emailTemplate->subject;
        return $this->SendEmail($email, $subject, $body);
    }
    private function sendForgotPasswordLink($request, $email, $emailTemplate, $user)
    {

        $body = $emailTemplate->body;
        $body = str_replace("[User's Name]", $user->company_name, $body);
        $subject = $emailTemplate->subject;
        return $this->SendEmail($email, $subject, $body);
    }
    private function sendKeyGenerateAlertMail($request, $email, $emailTemplate)
    {

        $body = $emailTemplate->body;
        // $body = str_replace('#otp', $request->otp, $body);
        $subject = $emailTemplate->subject;
        return $this->SendEmail($email, $subject, $body);
    }
    private function sendLoginAlertMail($request, $email, $emailTemplate)
    {

        $body = $emailTemplate->body;
        $body = str_replace('#otp', $request->otp, $body);
        $subject = $emailTemplate->subject;
        return $this->SendEmail($email, $subject, $body);
        // Send the email

        //return "Email sent successfully!";
    }
    private function sendBalanceAlertMail($request, $id, $emailTemplate, $user)
    {
        // $user = User::with(['balance', 'pricingModel'])->findOrFail($id);
        $user->load(['balance', 'roles', 'pricingModel']);
        $user->latest_balance = optional($user->balance()->latest()->first())->total_credits;
        $user->role_name = $user->roles[0]->name;
        $user->pricing = $user->pricingModel()->latest()->first()->price_alert;
        unset($user->balance);
        unset($user->roles);
        unset($user->pricingModel);


        $body = $emailTemplate->body;
        $body = str_replace('[User Name]', $user->name, $body);
        $body = str_replace('[Latest Balance]', $user->latest_balance, $body);
        $body = str_replace('[Price Alert]', $user->pricing, $body);
        $subject = $emailTemplate->subject;
        return $this->SendEmail($user->email, $subject, $body);
        // Send the email

        //return "Email sent successfully!";
    }


    private function SendEmail($email, $subject, $body)
    {

        try {
            Mail::to($email)->send(new SendEmail($subject, $body));
            return response()->json([
                'message' => 'Email sent successfully.',
                'status' => true
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to send email.',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function store(Request $request)
    {
        $template = new EmailTemplate();
        $template->user_id = $request->user_id;
        $template->template_id = $request->template_name;
        $template->subject = $request->subject;
        $template->body = $request->body;
        $template->status = 'active';
        $template->save();
        return response()->json(['status' => true, 'message' => 'Template Saved Successfully'], 200);
    }
    public function update(Request $request)
    {
        $template = EmailTemplate::find($request->id);
        $template->user_id = $request->user_id;
        $template->template_id = $request->template_name;
        $template->subject = $request->subject;
        $template->body = $request->body;
        $template->status = 'active';
        $template->save();
        return response()->json(['status' => true, 'message' => 'Template Updated Successfully'], 200);
    }
    public function destroy($id)
    {
        // Find the EmailTemplate by its ID
        $emailTemplate = EmailTemplate::find($id);

        // Check if the EmailTemplate exists
        if (!$emailTemplate) {
            return response()->json([
                'status' => false,
                'message' => 'Email Template not found.'
            ], 404);
        }

        // Delete the EmailTemplate
        $emailTemplate->delete();

        // Return a success response
        return response()->json([
            'status' => true,
            'message' => 'Email Template deleted successfully.'
        ], 200);
    }

}
