<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\User;
class ImpersonationController extends Controller
{
    public function impersonate(Request $request,$userId)
    {

        $admin = User::find($request->adminId);
       // return response()->json(['admin' => $admin->hasRole('Admin')], 200);
        // Ensure the current user is an admin
        if (!$admin || (!$admin->hasAnyRole(['Admin', 'Reseller']))) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Find the user to impersonate
        $user = User::with('userConfig', 'pricingModel', 'ApiKey')->find($userId);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }
        // Store the admin's ID in the session
        session(['admin_id' => $admin->id]);

        // Create a new token for the impersonated user
        $tokenResult = $user->createToken('ImpersonationToken');
        $token = $tokenResult->accessToken;
        // Retrieve the user's role
        $role = $user->roles->first();
        $rolePermissions = $role ? $role->permissions : collect();
        $userPermissions = $user->permissions;

        // Merge role and user permissions
        $allPermissions = $rolePermissions->merge($userPermissions)->unique('name');
        $allPermissionNames = $allPermissions->pluck('name');

        // Prepare user data
        $userArray = $user->toArray();
        $userArray['role'] = $role ? $role->name : null;
        $userArray['permissions'] = $allPermissionNames;
        // Return response with token and user data
        return response()->json([
            'message' => 'Impersonation successful',
            'token' => $token,
            'user' => $userArray,
        ]);
    }

    public function stopImpersonation()
    {
        $adminId = session('admin_id');
        return response()->json([
            'token' => $adminId,
        ]);
        // Get the admin's original user ID from the session
        $adminId = session('admin_id');
        // Check if admin ID exists in the session
        if (!$adminId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        $admin = User::find($adminId);
        if (!$admin || !$admin->hasRole('Admin')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        $token = $admin->createToken('MyAppToken')->accessToken;
        // Retrieve the user's role
        $role = $admin->roles->first();
        $rolePermissions = $role ? $role->permissions : collect();
        $userPermissions = $admin->permissions;

        // Merge role and user permissions
        $allPermissions = $rolePermissions->merge($userPermissions)->unique('name');
        $allPermissionNames = $allPermissions->pluck('name');

        // Prepare user data
        $userArray = $admin->toArray();
        $userArray['role'] = $role ? $role->name : null;
        $userArray['permissions'] = $allPermissionNames;

        return response()->json([
            'message' => 'Impersonation successful',
            'token' => $token,
            'user' => $userArray,
        ]);
    }
}
