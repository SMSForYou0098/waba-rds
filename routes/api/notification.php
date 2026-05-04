<?php

use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\Notification\NodeHelper;
use Illuminate\Support\Facades\Route;

// ─── Push Notifications ───────────────────────────────────────────────────────
Route::post('/save-fcm-token', [NotificationController::class, 'saveFcmToken'])->middleware(['auth:api']);
Route::post('/remove-fcm-token', [NotificationController::class, 'removeDeviceToken'])->middleware(['auth:api']);
Route::post('/send-notification', [NotificationController::class, 'sendNotification']);

// ─── Node Helper ──────────────────────────────────────────────────────────────
Route::post('/get-pricing', [NodeHelper::class, 'getPricing']);
Route::post('/deduct', [NodeHelper::class, 'deduct']);
Route::post('/update-report', [NodeHelper::class, 'update']);
Route::post('/get-single-chat', [NodeHelper::class, 'getSingleChat']);
Route::get('/report-exists/{messageId}', [NodeHelper::class, 'getExistingReport']);
Route::get('/latest-chat', [NodeHelper::class, 'latestChat']);
