<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Settings\Setting;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Session;
use Log;
use Spatie\Permission\Models\Role;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Password;
use App\Services\Email\EmailService;

class AuthController extends Controller
{
    protected $emailService;

    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    public function verifyOTP($email, $otp)
    {
        $loginCredential = $email;
        $cacheKey = 'otp_' . $loginCredential;
        //return response()->json(['data' => $loginCredential], 401);
        $cachedOtp = Cache::get($cacheKey);

        if ($cachedOtp && ($cachedOtp == $otp)) {
            return response()->json(['status' => true, 'OTP verified successfully'], 200);
        } else {
            return response()->json(['status' => false, 'error' => 'Invalid or expired OTP'], 401);
        }
    }

    public function userAuthenticate(Request $request)
    {
        try {
            $ip = request()->ip();
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json(['emailError' => 'Wrong email', 'ip' => $ip], 401);
            }
            // Rest of the login logic remains the same
            if (!$user->hasVerifiedEmail()) {
                return response()->json(['message' => 'Your email address is not verified.'], 403);
            }

            if ($user->status != 'active') {
                return response()->json(['error' => 'Your account has been blocked. Please contact administrator'], 401);
            }
            if ($user->ip_auth == 'true') {
                $userIPs = json_decode($user->ip_addresses, true);
                if (!is_array($userIPs) || !in_array($ip, $userIPs)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'IP authentication failed',
                        'ip' => $ip
                    ], 401);
                }
            }
            // Validate password
            if (!Hash::check($request->password, $user->password)) {
                return $this->HandleWrongPass($user, $request->email);
            }
            $email = $request->email;
            $cacheKey = 'login_attempt_' . $email;
            Cache::forget($cacheKey);

            $sessionKey = Str::random(40); // Generate a random session key
            Cache::put("auth_session_key_{$email}", $sessionKey, 600); // 600 = 10 minutes
            if ($user->two_fector_auth == 'true') {
                return $this->sendOTP($user, $sessionKey);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Two-Factor Authentication Disabled',
                    'two_fector_auth' => false,
                    'session_key' => $sessionKey
                ], 201);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to authenticate user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function sendOTP($user, $sessionKey)
    {
        $number = $user->phone_number;
        $email = $user->email;
        $otp = $this->generateOTP();

        $setting = Setting::select('sms_apiKey', 'sms_senderId')->first();

        $apiKey = optional($setting)->sms_apiKey;
        $smsSenderId = optional($setting)->sms_senderId;

        $message = "OTP for login is {$otp} and is valid for 5 minutes.(Generated at " . $this->generateFormattedDate() . ")";
        $otpApi = "https://login.smsforyou.biz/V2/http-api.php?apikey={$apiKey}&senderid={$smsSenderId}&number={$number}&message=" . urlencode($message) . "&format=json";

        try {
            $client = new Client();
            $response = $client->request('GET', $otpApi);
            $responseBody = json_decode($response->getBody(), true);
            $cacheKey = 'otp_' . $email;
            Cache::put($cacheKey, $otp, now()->addMinutes(5));

            return response()->json([
                'status' => true,
                'message' => 'Users  Successfully',
                'two_fector_auth' => (bool) $user->two_fector_auth,
                'session_key' => $sessionKey
            ], 201);
        } catch (RequestException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function login(Request $request)
    {
        try {
            $email = $request->input('email');
            $sessionKey = $request->input('sessionKey');
            if (!$sessionKey || !Cache::has("auth_session_key_{$email}") || Cache::get("auth_session_key_{$email}") !== $sessionKey) {
                return response()->json(['status' => false, 'message' => 'Unauthorized access'], 401);
            }

            $user = User::where('email', $email)->with([
                'userConfig',
                'pricingModel',
                'ApiKey',
                'supportAgent',
                'reportingUser.userConfig'
            ])->first();

            if (!$user) {
                return response()->json(['status' => false, 'message' => 'User not found'], 404);
            }

            // Generate API token
            //$token = $user->createToken('MyAppToken')->accessToken;
            $token = $user->createToken('MyAppToken', ['purchase-plan'])->accessToken;
            $role = $user->roles->first();

            // Get permissions (Merge role & user-specific permissions)
            $rolePermissions = $role ? $role->permissions : collect();
            $userPermissions = $user->permissions ?? collect();
            $allPermissions = $rolePermissions->merge($userPermissions)->unique('name')->pluck('name');

            // Convert user to array and add additional data
            $userArray = $user->toArray();
            $userArray['role'] = $role ? $role->name : null;
            $userArray['permissions'] = $allPermissions;
            unset($userArray['roles']);

            // Remove session key after successful login
            Session::forget("otp_session_key");

            return response()->json([
                'status' => true,
                'message' => 'Login successful',
                'token' => $token,
                'user' => $userArray
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Login failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    protected function HandleWrongPass($user, $email)
    {
        $cacheKey = 'login_attempt_' . $email;
        $attemptData = Cache::get($cacheKey, ['count' => 0, 'last_attempt' => null]);
        $lastAttemptTime = $attemptData['last_attempt'];
        if ($lastAttemptTime && now()->diffInMinutes($lastAttemptTime) < 1) {
            $attemptData['count']++;
        } else {
            $attemptData['count'] = 1;
            $attemptData['last_attempt'] = now();
        }
        Cache::put($cacheKey, $attemptData, now()->addMinutes(1));
        if ($attemptData['count'] >= 5) {
            return $this->DisableUser($user);
        }
        return response()->json(['code' => 'WP', 'passwordError' => 'Wrong password'], 401);
    }
    protected function DisableUser($user)
    {
        $user->status = 'inactive';
        $user->save();
        return response()->json(['error' => 'Your account has been blocked. Please contact administrator.'], 429);
    }
    public function backuplogin(Request $request)
    {
        // '111.125.194.83'
        $email = $request->input('email');
        $password = $request->input('password');
        $ip = file_get_contents('https://api.ipify.org');
        $user = User::where('email', $email)->with('userConfig')->with('pricingModel')->first();
        if ($user) {
            if (Hash::check($password, $user->password)) {
                $token = $user->createToken('MyAppToken')->accessToken;
                $role = $user->roles->first();
                $permissions = $role ? $role->permissions->pluck('name') : [];
                $userArray = $user->toArray();
                $userArray['role'] = $role;
                $userArray['permissions'] = $permissions;
                return response()->json(['token' => $token, 'user' => $userArray, 'ip' => $ip], 200);
            } else {
                return response()->json(['passwordError' => 'Wrong password', 'ip' => $ip], 401);
            }
        }
        return response()->json(['emailError' => 'Wrong email', 'ip' => $ip], 401);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'company_name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|integer',
            'password' => 'required|string|min:6',
        ]);

        $user = new User();

        $user->company_name = $request->company_name;
        $user->name = $request->name;
        $user->phone_number = $request->phone;
        $user->email = $request->email;
        $user->status = 'deactive';
        $user->password = Hash::make($request->password);

        $user->save();
        $role = Role::where('name', 'Viewer')->first();
        if ($role) {
            $user->assignRole($role);
        }
        // $this->SentRegisterMail($user);
        return response()->json(['status' => true, 'message' => 'User registered successfully', 'user' => $user], 201);
    }


    public function SentRegisterMail(Request $request)
    {
        try {
            // Generate the verification URL
            $userId = $request->id;
            $user = User::findOrFail($userId);
            $verificationUrl = route('verification.verify', [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification())
            ]);

            // Prepare the email data
            $emailData = [
                'template' => 'User Verification Mail',
                'email' => $user->email,
                'username' => $user->name,
                'verification_url' => $verificationUrl // Pass the verification URL
            ];

            // Send the email using Guzzle HTTP client
            $client = new Client();
            $response = $client->post(route('send-email', ['id' => $user->id]), [
                'form_params' => $emailData
            ]);
            // Handle the response
            if ($response->getStatusCode() == 200) {
                $data = json_decode($response->getBody(), true);
                // Process the data as needed
                return response()->json($data);
            } else {
                // Handle non-successful responses
                return response()->json([
                    'error' => 'Request failed with status ' . $response->getStatusCode()
                ], $response->getStatusCode());
            }
        } catch (\Exception $e) {
            // Handle exceptions
            return response()->json([
                'error' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }


    public function changePassword(Request $request, $id)
    {
        try {
            $loggedInUser = Auth::user();  // This fetches the authenticated user via Laravel's Auth system
            $isAdmin = $loggedInUser->hasRole('Admin');
            // Validate request data
            $rules = [
                'password' => 'required|min:8',
            ];
            if (!$isAdmin) {
                $rules['current_password'] = 'required';
            }
            $request->validate($rules);
            // return response()->json(['message' => $request->current_password, $request->password], 200);
            // Get the authenticated user
            $user = User::findOrFail($id);

            // Check if the current password matches the one provided
            if (!Hash::check($request->current_password, $user->password) && !$isAdmin) {
                throw new \Exception('Current password is incorrect');
            }

            // Update the user's password
            $user->password = Hash::make($request->password);
            $user->save();

            return response()->json([
                'status' => true,
                'message' => 'Password updated successfully',
                'email' => $user->email
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    function generateFormattedDate()
    {
        // Get the current date and time
        $currentDate = now();

        // Format the date as MM/DD/YYYY HH:MM:SS
        return $currentDate->format('m/d/Y H:i:s');
    }

    private function generateOTP($length = 6)
    {
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= mt_rand(0, 9);
        }
        return $otp;
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);
        $email = $request->email;
        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found'], 404);
        }

        // Generate token
        $token = app('auth.password.broker')->createToken($user);
        $encryptedToken = urlencode(\Crypt::encryptString($token));
        $resetUrl = "http://192.168.0.141:3000/reset-password/?token={$encryptedToken}&email={$user->email}";
        // $resetUrl = "https://web.smsforyou.biz/reset-password/?token={$encryptedToken}&email={$user->email}";

        // Fetch template from DB using query builder to avoid Eloquent/model issues
        $template = \DB::table('email_templates')->where('template_id', 'Forgot Password')->select('subject', 'body')->first();
        if ($template) {
            $body = $template->body;
            $body = str_replace(':URL:', $resetUrl, $body);
            $body = str_replace('[USER]', $user->company_name, $body);
            $subject = $template->subject;
        } else {
            $body = "Reset your password: $resetUrl";
            $subject = 'Reset Password';
        }
        // Send email (using Laravel's Mail or your own logic)
        $result = $this->emailService->sendEmail($email, $subject, $body);
        if ($result['success']) {
            return response()->json([
                'message' => $result['message'],
                'status' => true
            ], 200);
        } else {
            return response()->json([
                'message' => $result['message'],
                'status' => 'error',
                'error' => $result['error'] ?? null
            ], 500);
        }
    }
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email|exists:users,email',
            'password' => 'required|min:8|confirmed',
        ]);
        $decryptedToken = \Crypt::decryptString($request->token);
        $request->merge(['token' => $decryptedToken]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->password = Hash::make($password);
                $user->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'status' => true,
                'message' => __($status)
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => __($status)
            ], 400);
        }
    }
    public function verifyResetToken(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email|exists:users,email',
        ]);
        try {
            $decryptedToken = \Crypt::decryptString($request->token);
        } catch (\Exception $e) {
            return response()->json(['valid' => false, 'message' => 'Invalid token.'], 200);
        }
        $record = \DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();
        // Laravel hashes the token, so we need to check using Hash::check
        $valid = false;
        if ($record && \Hash::check($decryptedToken, $record->token)) {
            $valid = true;
        }
        return response()->json(['valid' => $valid], 200);
    }

}
