<?php

use App\Http\Controllers\Settings\SettingController;
use App\Http\Controllers\Settings\UserConfigController;
use App\Http\Controllers\Settings\DeveloperController;
use App\Http\Controllers\Settings\ErrorCodeController;
use Illuminate\Support\Facades\Route;

// ─── Settings ─────────────────────────────────────────────────────────────────
Route::get('/setting', [SettingController::class, 'index']);
Route::post('/update-setting', [SettingController::class, 'store'])->middleware(['auth:api']);
Route::post('/update-sms-config', [SettingController::class, 'storeSMSCongif'])->middleware(['auth:api']);
Route::post('/logdata/reprocess', [SettingController::class, 'updateLogdataReprocessedAt']);

// ─── User Configs (Meta Credentials, etc) ─────────────────────────────────────
Route::get('get-credential/{id}', [UserConfigController::class, 'index'])->middleware(['auth:api', 'check.permission:View Meta Credential']);
Route::post('update-credential', [UserConfigController::class, 'create'])->middleware(['auth:api']);
Route::post('update-chatbot-settings/{id}', [UserConfigController::class, 'HandleChatBotTime'])->middleware(['auth:api']);
Route::get('chatbot-setting/{id}', [UserConfigController::class, 'getChatBotSetting'])->middleware(['auth:api']);

// ─── Developer (API Keys) ─────────────────────────────────────────────────────
Route::get('api-key/{id}', [DeveloperController::class, 'index'])->middleware(['auth:api']);
Route::post('store-key', [DeveloperController::class, 'store'])->middleware(['auth:api']);
Route::post('update-key/{id}', [DeveloperController::class, 'update'])->middleware(['auth:api']);

// ─── Error Codes ──────────────────────────────────────────────────────────────
Route::post('/error-codes', [ErrorCodeController::class, 'store'])->middleware(['auth:api']);
Route::get('/error-codes', [ErrorCodeController::class, 'index'])->middleware(['auth:api']);
Route::put('/error-codes/{id}', [ErrorCodeController::class, 'update'])->middleware(['auth:api']);
