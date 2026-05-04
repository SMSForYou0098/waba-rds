<?php

use App\Http\Controllers\User\UserController;
use App\Http\Controllers\User\RolePermissionController;
use Illuminate\Support\Facades\Route;

// ─── Users ────────────────────────────────────────────────────────────────────
Route::get('users', [UserController::class, 'index'])->middleware(['auth:api', 'check.permission:View User']);
Route::get('low-credit-users/{id}', [UserController::class, 'lowBalanceUser'])->middleware(['auth:api', 'check.permission:View User']);
Route::post('create-user', [UserController::class, 'create'])->middleware(['auth:api', 'check.permission:View User']);
Route::get('edit-user/{id}', [UserController::class, 'edit'])->middleware(['auth:api', 'check.permission:View Edit Profile']);
Route::get('chek-user/{id}', [UserController::class, 'CheckValidUser'])->middleware(['auth:api']);
Route::post('chek-password', [UserController::class, 'checkPassword'])->middleware(['auth:api']);
Route::post('update-security', [UserController::class, 'UpdateUserSecurity'])->middleware(['auth:api', 'check.permission:View User Security']);
Route::post('update-user/{id}', [UserController::class, 'update'])->middleware(['auth:api', 'check.permission:View User Security']);
Route::post('update-user-alert/{id}', [UserController::class, 'updateAlerts'])->middleware(['auth:api']);
Route::post('get-mobile-numbers', [UserController::class, 'getMobileNumbersOptimized'])->middleware(['auth:api']);
Route::post('branding', [UserController::class, 'getBrandingConfiguration']);
Route::delete('/delete-users/{id}', [UserController::class, 'destroy'])->middleware(['auth:api']);
Route::get('configs-with-user', [UserController::class, 'getUsersWithConfig'])->middleware(['auth:api']);
Route::post('test', [UserController::class, 'test']);
Route::post('/send-otp', [UserController::class, 'sendOtp'])->middleware(['auth:api']);
Route::post('/verify-otp', [UserController::class, 'verifyOtp'])->middleware(['auth:api']);
Route::get('/logs', [UserController::class, 'logs'])->middleware(['auth:api']);
Route::delete('/clear-logs', [UserController::class, 'destroyLogs'])->middleware(['auth:api']);
Route::get('/users/balance-campaign-data', [UserController::class, 'getUsersBalanceAndCampaignData']);

// ─── Roles ────────────────────────────────────────────────────────────────────
Route::post('/create-role', [RolePermissionController::class, 'createRole'])->middleware(['auth:api', 'check.permission:View Role Permission']);
Route::get('/role-list', [RolePermissionController::class, 'getRoles']);
Route::get('/role-edit/{id}', [RolePermissionController::class, 'EditRole'])->middleware(['auth:api', 'check.permission:View Role Permission']);
Route::post('/role-update', [RolePermissionController::class, 'UpdateRole'])->middleware(['auth:api', 'check.permission:View Role Permission']);

// ─── Permissions ──────────────────────────────────────────────────────────────
Route::post('/create-permission', [RolePermissionController::class, 'createPermission'])->middleware(['auth:api', 'check.permission:Create Permission']);
Route::get('/permission-list', [RolePermissionController::class, 'getPermissions'])->middleware(['auth:api', 'check.permission:View Role Permission']);
Route::get('/permission-edit/{id}', [RolePermissionController::class, 'EditPermission'])->middleware(['auth:api', 'check.permission:View Role Permission']);
Route::post('/permission-update', [RolePermissionController::class, 'UpdatePermission'])->middleware(['auth:api', 'check.permission:View Role Permission']);

// ─── Role Permissions ─────────────────────────────────────────────────────────
Route::get('/role-permission/{id}', [RolePermissionController::class, 'getRolePermissions'])->middleware(['auth:api', 'check.permission:View Role Permission']);
Route::post('/role-permission/{id}', [RolePermissionController::class, 'giveRolePermissions'])->middleware(['auth:api', 'check.permission:View Role Permission']);
Route::get('/user-permission/{id}', [RolePermissionController::class, 'getUserPermissions'])->middleware(['auth:api', 'check.permission:View User Permission']);
Route::post('/user-permission/{id}', [RolePermissionController::class, 'giveUserPermissions'])->middleware(['auth:api', 'check.permission:View User Permission']);
Route::get('/change-viewer-role/{id}', [RolePermissionController::class, 'changeViewerRole']);
