<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\Auth\ImpersonationController;
use App\Http\Controllers\User\UserController;
use Illuminate\Support\Facades\Route;

// ─── Public Auth ──────────────────────────────────────────────────────────────
Route::post('login', [AuthController::class, 'login']);
Route::post('register', [AuthController::class, 'register']);
Route::post('register-mail', [AuthController::class, 'SentRegisterMail'])->name('register.mail');
Route::post('user-auth-authenticate', [AuthController::class, 'userAuthenticate']);
Route::post('verify-otp/{email}/{otp}', [AuthController::class, 'verifyOTP']);
Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');
Route::post('/verify-reset-token', [AuthController::class, 'verifyResetToken']);

// ─── Email Verification ───────────────────────────────────────────────────────
Route::get('verify-email/{id}', [UserController::class, 'verifyEmail']);
Route::get('verify-email/{id}/{hash}', [VerificationController::class, 'verify'])->name('verification.verify');

// ─── Authenticated Auth ───────────────────────────────────────────────────────
Route::post('update-password/{id}', [AuthController::class, 'changePassword'])->middleware(['auth:api']);

// ─── Impersonation ────────────────────────────────────────────────────────────
Route::post('/impersonate/{user}', [ImpersonationController::class, 'impersonate']);
Route::post('/impersonate/stop', [ImpersonationController::class, 'stopImpersonation']);
