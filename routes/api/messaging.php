<?php

use App\Http\Controllers\Messaging\MessagingController;
use App\Http\Controllers\Messaging\WhatsAppMessageRequest;
use App\Http\Controllers\Messaging\SendMessageByObject;
use App\Http\Controllers\Messaging\MessageController;
use App\Http\Controllers\Messaging\HandleBlockedNumber;
use Illuminate\Support\Facades\Route;

// ─── WhatsApp Messages ────────────────────────────────────────────────────────
Route::match(['get', 'post'], 'send-messages', [WhatsAppMessageRequest::class, 'sendMessages']);
Route::post('send-media-messages', [WhatsAppMessageRequest::class, 'sendMediaMessage']);
Route::post('/messages/{id}', [SendMessageByObject::class, 'sendMessage']);

// ─── Bulk campaign messaging ────────────────────────────────────────────────────
Route::post('validate-campaign', [MessagingController::class, 'validateCampaign'])->middleware(['auth:api', 'check.permission:View Campaign']);
Route::post('send-campaign', [MessagingController::class, 'sendCampaign'])->middleware(['auth:api', 'check.permission:View Campaign']);
Route::get('campaign-progress/{campaignId}', [MessagingController::class, 'campaignProgress'])->middleware(['auth:api', 'check.permission:View Campaign']);

// ─── Chatbot Login OTP ────────────────────────────────────────────────────────
Route::get('login-chatbot-Verify-otp/{otp}', [MessageController::class, 'ChatbotVerifyOTP']);

// ─── Blocked Numbers ──────────────────────────────────────────────────────────
Route::get('/get-block-numbers/{id}', [HandleBlockedNumber::class, 'index'])->middleware(['auth:api']);
Route::any('/block-number', [HandleBlockedNumber::class, 'create']);
Route::post('/update-block-contact/{id}', [HandleBlockedNumber::class, 'updateUserBlockChatbotAccess'])->middleware(['auth:api']);
Route::post('/delete-block-contact/{id}', [HandleBlockedNumber::class, 'destroy'])->middleware(['auth:api']);
