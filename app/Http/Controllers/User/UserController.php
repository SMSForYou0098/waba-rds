<?php

namespace App\Http\Controllers\User;
use App\Http\Controllers\Controller;
use App\Models\Auth\ApiKey;
use App\Models\Report\Logdata;
use App\Models\Settings\UserConfig;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Storage;
use App\Models\Settings\BrandingConfiguration;
use DB;
class UserController extends Controller
{

public function index()
{
    $user = Auth::user();
    $isAdmin = $user->hasRole('Admin');
    $isAgent = $user->hasRole('Support Agent');

    // Generate unique cache key based on user role and ID
    $cacheKey = "users_list_{$user->id}_{$isAdmin}_{$isAgent}";

    // Get data from cache or execute query if cache doesn't exist
    $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($user, $isAdmin, $isAgent) {
        
        // Get roles once (they rarely change)
        $roles = Cache::remember('roles_list', now()->addHours(1), function () {
            return DB::table('roles')->select('id', 'name')->get();
        });

        // Optimized single query with all necessary joins
        $query = DB::table('users')
            ->select([
                'users.id',
                'users.company_name',
                'users.name',
                'users.email',
                'users.status',
                'users.phone_number',
                'users.whatsapp_number',
                'users.reporting_user',
                'users.user_billing',
                'users.created_at',
                'roles.name as role_name',
                'reporting_users.name as reporting_user_name',
                'balances.total_credits as latest_balance',
                DB::raw('CASE WHEN chatbots.user_id IS NOT NULL THEN 1 ELSE 0 END as has_chatbot')
            ])
            ->leftJoin('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->leftJoin('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->leftJoin('users as reporting_users', 'users.reporting_user', '=', 'reporting_users.id')
            ->leftJoin(
                DB::raw('(SELECT DISTINCT user_id, 
                          FIRST_VALUE(total_credits) OVER (PARTITION BY user_id ORDER BY id DESC) as total_credits
                          FROM balances) as balances'),
                'users.id', '=', 'balances.user_id'
            )
            ->leftJoin(
                DB::raw('(SELECT DISTINCT user_id FROM chatbots) as chatbots'),
                'users.id', '=', 'chatbots.user_id'
            )
            ->whereNull('users.deleted_at');

        // Apply role-based filters
        if (!$isAdmin && !$isAgent) {
            $query->where('users.reporting_user', $user->id);
        }

        // Exclude Support Agents using NOT EXISTS for better performance
        $query->whereNotExists(function ($subQuery) {
            $subQuery->select(DB::raw(1))
                ->from('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('roles.name', 'Support Agent');
        });

        // Get users with ordering and convert to array immediately
        $users = $query->orderBy('users.created_at', 'desc')
                      ->get()
                      ->map(function ($user) {
                          return [
                              'id' => $user->id,
                              'company_name' => $user->company_name,
                              'name' => $user->name,
                              'email' => $user->email,
                              'user_billing' => $user->user_billing,
                              'status' => $user->status,
                              'phone_number' => $user->phone_number,
                              'whatsapp_number' => $user->whatsapp_number,
                              'created_at' => $user->created_at,
                              'latest_balance' => $user->latest_balance,
                              'role_name' => $user->role_name,
                              'rp' => $user->reporting_user_name,
                              'hasChatbot' => (bool)$user->has_chatbot,
                          ];
                      });

        return [
            'users' => $users,
            'roles' => $roles
        ];
    });

    return response()->json([
        'user' => $data['users'],
        'roles' => $data['roles']
    ]);
}

	public function getUsersWithConfig()
    {
        // Eager load the 'userConfig' relationship for each user
        $configs = UserConfig::with([
            'user' => function ($query) {
                $query->select('id', 'company_name'); // Select only the id and company_name
            }
        ])
            ->get();

        return response()->json([
            'status' => 'true',
            'data' => $configs
        ]);
    }
    public function create(Request $request)
    {
		 $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'number' => 'required|integer',
            'companyName' => 'required|string|max:255',
            'status' => 'required|string',
            'password' => 'required',
            'reportingUser' => 'nullable|integer',
            'role_id' => 'required',
        ]);
        try {
            $user = new User();
            $user->name = $request->name;
            $user->email = $request->email;
            $user->phone_number = $request->number;
            $user->company_name = $request->companyName;
          	$user->user_billing = $request->user_billing;
            $user->status = $request->status;
            if ($request->status === 'active') {
                $user->email_verified_at = now();
            }
            $user->password = Hash::make($request->password);
            $user->reporting_user = $request->reportingUser;
            $role = Role::where('id', $request->role_id)->first();
            if ($role) {
                $user->assignRole($role);
            }
            $user->save();

            $response = ['status' => 'true', 'message' => 'User Created Successfully'];

            // Handle white label (branding configuration) for Resellers
            if ($request->role_name === 'Reseller' && $request->white_lable === true) {
                $brandingResult = $this->handleBrandingConfiguration($user, $request);
                $response = array_merge($response, $brandingResult);
            }

            return response()->json($response, 201);
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Error creating user: ' . $e->getMessage());

            // Return an error response
            return response()->json(['status' => 'false', 'message' => 'Failed to create user' . $e->getMessage(),], 500);
        }

    }

    public function verifyEmail($id)
    {
        // Find the user by ID
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Check if the user is already verified
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'User is already verified'], 200);
        }

        // Manually verify the email by setting the email_verified_at field to the current timestamp
        $user->email_verified_at = Carbon::now();
        $user->save();

        return response()->json(['message' => 'User email verified successfully'], 200);
    }
    public function CheckValidUser($id)
    {
        try {
            $user = User::where('id', $id)->with(['balance', 'pricingModel'])->get();
            $user->each(function ($user) {
                $user->latest_balance = $user->balance()->latest()->first();
                $user->pricing = $user->pricingModel()->latest()->first();
                unset($user->balance);
                unset($user->pricingModel);
            });
            $user_balance = $user[0]->latest_balance->total_credits ?? 00.00;
            $marketing_price = $user[0]->pricing->marketing_price;
            if ($user_balance < $marketing_price) {
                $user_balance = $user[0]->latest_balance->total_credits ?? 0;
                return response()->json(['status' => false, 'message' => 'insufficient credits', 'balance' => $user_balance]);
            } else {
                return response()->json(['status' => true, 'balance' => $user_balance]);
            }
        } catch (QueryException $e) {
            $errorMessage = $e->getMessage();
            return response()->json(['status' => false, 'message' => 'Query Exception: ' . $errorMessage]);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            return response()->json(['status' => false, 'message' => 'An error occurred while processing the request.' . $errorMessage]);
        }

    }

    public function UpdateUserSecurity(Request $request)
    {
        try {
            $user = User::where('id', $request->id)->firstOrFail();

            $user->ip_auth = $request->ip_auth == true ? 'true' : 'false';
            $user->two_fector_auth = $request->two_fector_auth == true ? 'true' : 'false';
            $user->ip_addresses = $request->ip_addresses;
            $user->save();
            return response()->json(['status' => true, 'message' => 'Security Method Updated Successfully', 'email' => $user->email]);
        } catch (QueryException $e) {
            $errorMessage = $e->getMessage();
            return response()->json(['status' => false, 'message' => 'Query Exception: ' . $errorMessage]);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            return response()->json(['status' => false, 'message' => 'An error occurred while processing the request.' . $errorMessage]);
        }
    }


    public function checkPassword(Request $request)
    {
        $user = User::find($request->id);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $password = $request->password;

        if (Hash::check($password, $user->password)) {
            return response()->json(['message' => 'Password is correct, you are verified successfully'], 200);
        } else {
            return response()->json(['error' => 'Oops! Password is incorrect'], 401);
        }
    }

    public function CreditLimit(Request $request)
    {
        $user = User::firstOrFail($request->id);
        $user->low_credit_limit = $request->amount;
        $user->save();
        return response()->json(['message' => 'Limit Updated Successfully'], 200);
    }

    public function edit(string $id)
    {
        $allUser = User::all();
        $roles = Role::all();
        $users = User::with('reportingUser')->where('id', $id)->get();
        $usersWithReportingUserNames = $users->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'company_name' => $user->company_name,
                'phone_number' => $user->phone_number,
                'password' => $user->password,
                'white_lable' => $user->white_lable,
                'two_fector_auth' => $user->two_fector_auth,
              	'user_billing' => $user->user_billing,
                'ip_auth' => $user->ip_auth,
                'ip_addresses' => $user->ip_addresses,
                'role' => $user->roles->first(),
                'email_alert' => $user->email_alerts,
                'status' => $user->status,
                'whatsapp_alert' => $user->whatsapp_alerts,
                'sms_alert' => $user->text_alerts,
                'reporting_user' => $user->reportingUser,
                'branding_configuration' => $user->brandingConfiguration ? [
                    'id' => $user->brandingConfiguration->id,
                    'logo' => $user->brandingConfiguration->logo,
                  	'login_bg' => $user->brandingConfiguration->login_bg,
                    'terms' => $user->brandingConfiguration->terms,
                    'privacy' => $user->brandingConfiguration->privacy,
                  	'host_url'=> $user->brandingConfiguration->host_url,
                    'copyright' => $user->brandingConfiguration->copyright,
                    'created_at' => $user->brandingConfiguration->created_at,
                    'updated_at' => $user->brandingConfiguration->updated_at,
                ] : null
            ];
        });
        return response()->json(['user' => $usersWithReportingUserNames, 'allUser' => $allUser, 'roles' => $roles]);
    }
    private function updateApiKeyStatus($userId, $status = "false")
    {
        if ($latestApiKey = ApiKey::where('user_id', $userId)->latest()->first()) {
            $latestApiKey->status = $status;
            $latestApiKey->save();
        }
    }
    public function update(Request $request, string $id)
    {
        try {
            // Validate the request data, excluding the user's current email from unique check
            $this->validate($request, [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email,' . $id,
                'number' => 'required|integer',
                'reportingUser' => 'nullable|integer',
            ]);
            // Find the user or throw a 404 if not found
            $user = User::findOrFail($id);

            // Update the user attributes except for the password
            $user->name = $request->name;
            $user->email = $request->email;
          	$user->white_lable = $request->white_lable;
            $user->company_name = $request->company_name;
            $user->user_billing = $request->user_billing;
            $user->phone_number = $request->number;
            $user->status = $request->status;

            if ($request->status === 'active') {
                $user->email_verified_at = now();
            }

            $user->reporting_user = $request->reportingUser;

            // Find and assign the role
            $role = Role::where('id', $request->role_id)->first();
            if ($role) {
                $user->syncRoles($role); // syncRoles will replace any existing roles with the new one
            }
            if ($request->status === 'active') {
                $this->updateApiKeyStatus($id, "true");
            } elseif ($request->status === 'inactive') {
                $this->updateApiKeyStatus($id);
            }
            // Save the user record
            $user->save();
            $response = ['status' => 'true', 'message' => 'User Updated Successfully'];

            // Handle white label (branding configuration) for Resellers
            if ($request->role_name == 'Reseller' && (int) $request->white_lable == 1){
                $brandingResult = $this->handleBrandingConfiguration($user, $request);
                $response = array_merge($response, $brandingResult);
            }
          	return response()->json($response, 201);
        } catch (\Exception $e) {
            return response()->json(['status' => 'false', 'message' => $e->getMessage()], 500);
        }
    }

    private function activateLatestApiKey($userId)
    {
        $latestApiKey = ApiKey::where('user_id', $userId)->latest()->first();
        //if ($latestApiKey->status == false) {
            $latestApiKey->status = "true";
            $latestApiKey->save();
        //}
    }

    private function deactivateLatestApiKey($userId)
    {
        $latestApiKey = ApiKey::where('user_id', $userId)->latest()->first();

        if ($latestApiKey) {
            $latestApiKey->status = "false";
            $latestApiKey->save();
        }
    }


    public function updateAlerts(Request $request, string $id)
    {
        try {
            $user = User::findOrFail($id); // Assuming $userId is the ID of the user you want to update
            if ($request->email_alerts) {
                $user->email_alerts = $request->email_alerts;
            } else if ($request->whatsapp_alerts) {
                $user->whatsapp_alerts = $request->whatsapp_alerts;
            } else if ($request->text_alerts) {
                $user->text_alerts = $request->text_alerts;
            }
            $user->save();

            return response()->json(['status' => 'true', 'message' => 'User Updated Successfully'], 200);
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Error updating user: ' . $e->getMessage());

            // Return an error response
            return response()->json(['status' => 'false', 'message' => 'Failed to update user'], 500);
        }

    }

    public function lowBalanceUser($id)
    {
        $users = DB::table('users as u')
        ->select([
            'u.id',
            'u.name',
            'u.email',
            'u.whatsapp_number',
            'u.phone_number',
            'u.email_alerts',
            'u.whatsapp_alerts',
            'u.text_alerts',
            'b.total_credits as latest_balance',
            'p.price_alert',
            'a.key as api_key'
        ])
        ->join(DB::raw('(SELECT user_id, MAX(id) as max_id FROM balances GROUP BY user_id) as latest_b'), function($join) {
            $join->on('u.id', '=', 'latest_b.user_id');
        })
        ->join('balances as b', function($join) {
            $join->on('latest_b.max_id', '=', 'b.id');
        })
        ->leftJoin(DB::raw('(SELECT user_id, MAX(id) as max_id FROM pricing_models GROUP BY user_id) as latest_p'), function($join) {
            $join->on('u.id', '=', 'latest_p.user_id');
        })
        ->leftJoin('pricing_models as p', function($join) {
            $join->on('latest_p.max_id', '=', 'p.id');
        })
        ->leftJoin(DB::raw('(SELECT user_id, MAX(id) as max_id FROM api_keys GROUP BY user_id) as latest_a'), function($join) {
            $join->on('u.id', '=', 'latest_a.user_id');
        })
        ->leftJoin('api_keys as a', function($join) {
            $join->on('latest_a.max_id', '=', 'a.id');
        })
        ->whereRaw('b.total_credits < p.price_alert')
        ->get();

    	return response()->json(['user' => $users]);
    }

    public function destroyLogs()
    {
        Logdata::truncate();
        return response()->json(['status' => 'true'], 200);
    }

    public function logs()
    {
        $logdata = Logdata::whereDate('created_at', Carbon::today())->get();
        return response()->json(['status' => 'true', 'logs' => $logdata], 200);
    }

    public function destroy($id)
    {
        // Find the user by ID
        $user = User::find($id);

        // Check if the user exists
        if (!$user) {
            return response()->json(['message' => 'User not found'], 401);
        }

        // Soft delete the user
        $user->delete();

        return response()->json(['message' => 'User deleted successfully'], 200);
    }


    public function sendOtp(Request $request)
    {
        $number = $request->number;
        $smsApiKey = $request->smsApiKey;
        $smsSenderId = $request->smsSenderId;
        $otp = rand(100000, 999999); // Generate a 6-digit OTP

        // Store OTP in cache for 5 minutes (300 seconds) using the phone number as a unique key
        Cache::put($number, $otp, 300); // Cache the OTP for 5 minutes

        // Send the OTP via SMS
        $response = $this->sendOtpSms($number, $otp, $smsApiKey, $smsSenderId);

        if ($response->successful()) {
            return response()->json(['message' => 'OTP sent successfully']);
        } else {
            return response()->json(['error' => 'Failed to send OTP'], 500);
        }
    }

    public function verifyOtp(Request $request)
    {
        $number = $request->input('number');
        $otp = $request->input('otp');

        // Retrieve the stored OTP from the cache
        $cachedOtp = Cache::get($number);

        if ($cachedOtp && $cachedOtp == $otp) {
            // OTP verified successfully
            Cache::forget($number); // Remove the OTP from the cache after verification
            return response()->json(['message' => 'OTP verified successfully']);
        } else {
            // Invalid OTP
            return response()->json(['error' => 'Invalid OTP or OTP expired'], 401);
        }
    }

    // Private function to generate the OTP message
    private function generateMessage($otp)
    {
        $formattedDate = $this->generateFormattedDate();
        $message = "OTP for login is {$otp} and is valid for 5 minutes.(Generated at {$formattedDate})";
        return $message;
    }

    // Private function to format the current date and time
    private function generateFormattedDate()
    {
        $currentDate = now(); // Laravel's now() helper

        // Format the date as "MM/DD/YYYY HH:MM:SS"
        return $currentDate->format('m/d/Y H:i:s');
    }

    // Private function to handle the SMS sending process
    private function sendOtpSms($number, $otp, $smsApiKey, $smsSenderId)
    {
        // Generate the message
        $message = $this->generateMessage($otp);

        // Construct the API URL and parameters
        $url = "https://login.smsforyou.biz/V2/http-api.php";
        $params = [
            'apikey' => $smsApiKey,
            'senderid' => $smsSenderId,
            'number' => $number,
            'message' => $message,
            'format' => 'json',
        ];
        //return $params;
        // Send the request using Laravel's HTTP client
        return Http::get($url, $params);
    }

	public function getBrandingConfiguration(Request $request)
    {
        try {
            // Validate the request
            $request->validate([
                'host_url' => 'required|string'
            ]);

            $hostUrl = $request->host_url;

            // Find branding configuration by host_url
            $brandingConfig = BrandingConfiguration::where('host_url', $hostUrl)->first();

            if (!$brandingConfig) {
                return response()->json([
                    'status' => 'false',
                    'message' => 'No branding configuration found for this host URL'
                ], 404);
            }

            // Get user information
            $user = User::find($brandingConfig->user_id);

            if (!$user) {
                return response()->json([
                    'status' => 'false',
                    'message' => 'User not found for this branding configuration'
                ], 404);
            }

            // Check if user's white_lable field is true
            if (!$user->white_lable) {
                return response()->json([
                    'status' => 'false',
                    'message' => 'White label is not enabled for this user'
                ], 403);
            }

            return response()->json([
                'status' => 'true',
                'message' => 'Branding configuration retrieved successfully',
                'data' => $brandingConfig,
                // 'user_info' => [
                //     'id' => $user->id,
                //     'name' => $user->name,
                //     'email' => $user->email,
                //     'company_name' => $user->company_name,
                //     'white_lable' => $user->white_lable,
                // ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'false',
                'message' => 'An error occurred while retrieving branding configuration: ' . $e->getMessage()
            ], 500);
        }
    }

  	private function handleBrandingConfiguration(User $user, Request $request)
    {
        try {
            $imagePath = null;
            // Handle logo - check if it's a file upload or URL
            if ($request->hasFile('white_label_logo')) {
                $file = $request->file('white_label_logo');
                $imagePath = $this->storeFile($file);
            } else {
                $imagePath = $request->white_label_logo;
            }
            $loginBgPath = null;
            // Handle login background - check if it's a file upload or URL
            if ($request->hasFile('login_bg')) {
                $file = $request->file('login_bg');
                $loginBgPath = $this->storeFile($file);
            } else {
                $loginBgPath = $request->login_bg;
            }
            $brandingConfig = BrandingConfiguration::updateOrCreate(
                ['user_id' => $user->id], // Search criteria
                [
                    'logo' => $imagePath ?? null,
                    'terms' => $request->terms_url ?? null,
                    'privacy' => $request->privacy_url ?? null,
                  	'host_url' => $request->host_url,
                    'copyright' => $request->copyright,
                    'login_bg' => $loginBgPath ?? null,
                ]
            );

            return [
                'branding_status' => 'success',
                'branding_message' => 'Branding configuration processed successfully',
                'branding_config_id' => $brandingConfig->id
            ];
        } catch (\Exception $e) {
            return [
                'branding_status' => 'error',
                'branding_message' => 'Error processing branding configuration: ' . $e->getMessage()
            ];
        }
    }

    protected function storeFile($file, $storageDisk = 'uploads')
    {
        $path = $file->store('brand_media', $storageDisk);
        $url = Storage::disk($storageDisk)->url($path);
        return $url;
    }
  	
  
    public function getMobileNumbersOptimized(Request $request)
    {
        try {
            $option = $request->get('option');

            if (!$option) {
                return response()->json(['error' => 'Option parameter is required'], 400);
            }

            // Parse option
            if ($option === 'all') {
                $mobileNumbers = DB::table('users as u')
                    ->where('u.status', 'active')
                  	->whereNull('u.deleted_at')
                    ->select('u.phone_number')
                    ->join('model_has_roles as mhr', 'u.id', '=', 'mhr.model_id')
                    ->join('roles as r', 'mhr.role_id', '=', 'r.id')
                    ->whereIn('r.name', ['User', 'Reseller'])
                    ->whereNotNull('u.phone_number')
                    ->where('u.phone_number', '!=', '')
                    ->distinct()
                    ->pluck('phone_number')
                    ->toArray();

                return response()->json([
                    'success' => true,
                    'option' => $option,
                    'count' => count($mobileNumbers),
                    'phone_numbers' => $mobileNumbers
                ]);
            }

            // Parse option for specific roles
            [$roleType, $chatbotFilter] = $this->parseOption($option);

            if (!$roleType) {
                return response()->json(['error' => 'Invalid option provided'], 400);
            }

            // Single optimized query
            $query = DB::table('users as u')
                ->select('u.phone_number')
                ->join('model_has_roles as mhr', 'u.id', '=', 'mhr.model_id')
                ->join('roles as r', 'mhr.role_id', '=', 'r.id')
                ->where('r.name', $roleType)
                ->where('u.status', 'active')
                ->whereNotNull('u.phone_number')
                ->where('u.phone_number', '!=', '');

            // Apply chatbot filter
            if ($chatbotFilter === 'including_chatbot') {
                $query->whereExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('chatbots as cb')
                        ->whereColumn('cb.user_id', 'u.id');
                });
            } elseif ($chatbotFilter === 'excluding_chatbot') {
                $query->whereNotExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('chatbots as cb')
                        ->whereColumn('cb.user_id', 'u.id');
                });
            }

            $mobileNumbers = $query->pluck('phone_number')->toArray();

            return response()->json([
                'success' => true,
                'option' => $option,
                'count' => count($mobileNumbers),
                'phone_numbers' => $mobileNumbers
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'An error occurred while fetching mobile numbers: ' . $e->getMessage()
            ], 500);
        }
    }
  
  	private function parseOption($option)
    {
        $parts = explode('_', $option);

        if (count($parts) < 2) {
            return [null, null];
        }

        $roleType = ucfirst($parts[0]); // User or Reseller
        $filterType = implode('_', array_slice($parts, 1)); // all, including_chatbot, excluding_chatbot

        if (!in_array($roleType, ['User', 'Reseller'])) {
            return [null, null];
        }

        if (!in_array($filterType, ['all', 'including_chatbot', 'excluding_chatbot'])) {
            return [null, null];
        }

        return [$roleType, $filterType];
    }
  
  
        public function getUsersBalanceAndCampaignData()
    {
        try {
            $currentMonth = Carbon::now()->startOfMonth();

            $data = DB::table('users as u')
                ->select([
                    'u.id',
                    'u.company_name',
                    // Current month first date balance
                    DB::raw('(SELECT total_credits FROM balances
                         WHERE user_id = u.id
                         AND DATE(created_at) >= "' . $currentMonth->toDateString() . '"
                         ORDER BY created_at ASC
                         LIMIT 1) as month_start_balance'),
                    // Today's latest balance
                    DB::raw('(SELECT total_credits FROM balances
                         WHERE user_id = u.id
                         ORDER BY created_at DESC
                         LIMIT 1) as current_balance'),
                    // Campaign count for current month
                    DB::raw('(SELECT COUNT(*) FROM campaigns
                         WHERE user_id = u.id
                         AND created_at >= "' . $currentMonth->toDateTimeString() . '") as total_campaigns_this_month')
                ])
                ->whereNull('u.deleted_at')
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->whereRaw('model_has_roles.model_id = u.id')
                        ->whereNotIn('roles.name', ['Support Agent', 'Admin']);
                })
                ->orderBy('u.company_name')
                ->get();

            // Calculate campaign costs for each user individually
            $users = [];
            $totalSummary = [
                'total_users' => 0,
                'total_campaigns_all_users' => 0,
                'total_campaign_cost_all_users' => 0,
                'total_current_balance_all_users' => 0
            ];

            foreach ($data as $user) {
                $campaignDetails = $this->getUserCampaignDetails($user->id, $currentMonth);

                $userData = [
                    'user_id' => $user->id,
                    'company_name' => $user->company_name,
                    'total_campaigns_this_month' => (int) $user->total_campaigns_this_month,
                    'total_campaign_cost_this_month' => (float) $campaignDetails['total_cost'],
                    'month_start_balance' => (float) ($user->month_start_balance ?? 0),
                    'current_balance' => (float) ($user->current_balance ?? 0),
                    'balance_difference' => (float) (($user->current_balance ?? 0) - ($user->month_start_balance ?? 0)),
                    'campaigns' => $campaignDetails['campaigns'] // Detailed campaign list
                ];

                $users[] = $userData;

                // Update summary totals
                $totalSummary['total_users']++;
                $totalSummary['total_campaigns_all_users'] += $userData['total_campaigns_this_month'];
                $totalSummary['total_campaign_cost_all_users'] += $userData['total_campaign_cost_this_month'];
                $totalSummary['total_current_balance_all_users'] += $userData['current_balance'];
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Users balance and campaign data retrieved successfully',
                'users' => $users, // Individual user data with campaign details
                'summary' => $totalSummary
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error retrieving users balance and campaign data: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve users balance and campaign data: ' . $e->getMessage()
            ], 500);
        }
    }
  
    private function getUserCampaignDetails($userId, $currentMonth)
    {
        try {
            // Get user's latest pricing model
            $userPricing = DB::table('pricing_models')
                ->where('user_id', $userId)
                ->orderBy('created_at', 'DESC')
                ->first();

            if (!$userPricing) {
                return [
                    'campaigns' => [],
                    'total_cost' => 0.0
                ];
            }

            // Get campaigns for this user in current month
            $campaigns = DB::table('campaigns')
                ->select('id', 'name', 'created_at')
                ->where('user_id', $userId)
                ->where('created_at', '>=', $currentMonth->toDateTimeString())
                ->orderBy('created_at', 'DESC')
                ->get();

            $campaignDetails = [];
            $totalCost = 0.0;

            foreach ($campaigns as $campaign) {
                // Get campaign reports with template_category for this campaign
                $campaignReports = DB::table('campaign_reports')
                    ->select('template_category', DB::raw('COUNT(*) as count'))
                    ->where('campaign_id', $campaign->id)
                    ->groupBy('template_category')
                    ->get();

                $campaignCost = 0.0;
                $totalReports = 0;
                $categoryBreakdown = [];

                foreach ($campaignReports as $report) {
                    $price = $this->getPriceByCategory($userPricing, $report->template_category);
                    $cost = $report->count * $price;
                    $campaignCost += $cost;
                    $totalReports += $report->count;

                    $categoryBreakdown[] = [
                        'template_category' => $report->template_category,
                        'count' => (int) $report->count,
                        'price_per_message' => (float) $price,
                        'subtotal' => (float) $cost
                    ];
                }

                $totalCost += $campaignCost;

                $campaignDetails[] = [
                    'campaign_id' => $campaign->id,
                    'name' => $campaign->name,
                    'total_reports_count' => $totalReports,
                    'total_campaign_cost' => (float) $campaignCost,
                    'category_breakdown' => $categoryBreakdown,
                    'created_at' => $campaign->created_at
                ];
            }

            return [
                'campaigns' => $campaignDetails,
                'total_cost' => $totalCost
            ];

        } catch (\Exception $e) {
            \Log::error("Error getting campaign details for user {$userId}: " . $e->getMessage());
            return [
                'campaigns' => [],
                'total_cost' => 0.0
            ];
        }
    }
  
    private function getPriceByCategory($userPricing, $templateCategory)
    {
        switch (strtolower($templateCategory)) {
            case 'marketing':
                return (float) ($userPricing->marketing_price ?? 0);
            case 'utility':
                return (float) ($userPricing->utility_price ?? 0);
            case 'authentication':
                return (float) ($userPricing->authentication_price ?? 0);
            case 'service':
                return (float) ($userPricing->service_price ?? 0);
            default:
                // Default to marketing price if category not found
                return (float) ($userPricing->marketing_price ?? 0);
        }
    }
  
  

}
