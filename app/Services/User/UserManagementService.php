<?php

namespace App\Services\User;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class UserManagementService
{
    public function __construct(
        private readonly UserBrandingService $brandingService,
        private readonly UserApiKeyService $apiKeyService,
    ) {}

    /**
     * @return array{status: string, message: string, http_status: int}&array<string, mixed>
     */
    public function create(Request $request): array
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
            $user = new User;
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

            $response = [
                'status' => 'true',
                'message' => 'User Created Successfully',
                'http_status' => 201,
            ];

            if ($request->role_name === 'Reseller' && $request->white_lable === true) {
                $response = array_merge($response, $this->brandingService->syncForUser($user, $request));
            }

            return $response;
        } catch (\Exception $e) {
            Log::error('Error creating user: '.$e->getMessage());

            return [
                'status' => 'false',
                'message' => 'Failed to create user'.$e->getMessage(),
                'http_status' => 500,
            ];
        }
    }

    /**
     * @return array{status: string, message: string, http_status: int}&array<string, mixed>
     */
    public function update(Request $request, string $id): array
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$id,
            'number' => 'required|integer',
            'reportingUser' => 'nullable|integer',
        ]);

        try {
            $user = User::findOrFail($id);
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

            $role = Role::where('id', $request->role_id)->first();
            if ($role) {
                $user->syncRoles($role);
            }

            if ($request->status === 'active') {
                $this->apiKeyService->updateLatestStatus($id, 'true');
            } elseif ($request->status === 'inactive') {
                $this->apiKeyService->updateLatestStatus($id);
            }

            $user->save();

            $response = [
                'status' => 'true',
                'message' => 'User Updated Successfully',
                'http_status' => 201,
            ];

            if ($request->role_name == 'Reseller' && (int) $request->white_lable == 1) {
                $response = array_merge($response, $this->brandingService->syncForUser($user, $request));
            }

            return $response;
        } catch (\Exception $e) {
            return [
                'status' => 'false',
                'message' => $e->getMessage(),
                'http_status' => 500,
            ];
        }
    }

    /**
     * @return array{message: string, http_status: int}
     */
    public function verifyEmail(string $id): array
    {
        $user = User::find($id);

        if (! $user) {
            return ['message' => 'User not found', 'http_status' => 404];
        }

        if ($user->hasVerifiedEmail()) {
            return ['message' => 'User is already verified', 'http_status' => 200];
        }

        $user->email_verified_at = Carbon::now();
        $user->save();

        return ['message' => 'User email verified successfully', 'http_status' => 200];
    }

    /**
     * @return array{status: string, message: string, http_status: int}
     */
    public function updateAlerts(Request $request, string $id): array
    {
        try {
            $user = User::findOrFail($id);

            if ($request->email_alerts) {
                $user->email_alerts = $request->email_alerts;
            } elseif ($request->whatsapp_alerts) {
                $user->whatsapp_alerts = $request->whatsapp_alerts;
            } elseif ($request->text_alerts) {
                $user->text_alerts = $request->text_alerts;
            }

            $user->save();

            return [
                'status' => 'true',
                'message' => 'User Updated Successfully',
                'http_status' => 200,
            ];
        } catch (\Exception $e) {
            Log::error('Error updating user: '.$e->getMessage());

            return [
                'status' => 'false',
                'message' => 'Failed to update user',
                'http_status' => 500,
            ];
        }
    }

    /**
     * @return array{message: string, http_status: int}
     */
    public function destroy(string $id): array
    {
        $user = User::find($id);

        if (! $user) {
            return ['message' => 'User not found', 'http_status' => 401];
        }

        $user->delete();

        return ['message' => 'User deleted successfully', 'http_status' => 200];
    }
}
