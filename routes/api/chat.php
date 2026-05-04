<?php

use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Chat\ChatBotController;
use App\Http\Controllers\Chat\ChatbotAuthController;
use App\Http\Controllers\Chat\DefaultChatbotController;
use App\Http\Controllers\Chat\SupportAgentController;
use Illuminate\Support\Facades\Route;

// ─── Chats ────────────────────────────────────────────────────────────────────
Route::get('/chats/{id}', [ChatController::class, 'chats'])->middleware(['auth:api']);
Route::post('/new-chat/{id}', [ChatController::class, 'NewChat'])->middleware(['auth:api']);
Route::get('message/{number}', [ChatController::class, 'getMessagesByNumber'])->middleware(['auth:api']);
Route::get('message/{number}/paginated', [ChatController::class, 'getMessagesByNumberPaginated'])->middleware(['auth:api']);

// ─── Chatbot ──────────────────────────────────────────────────────────────────
Route::get('/chatbot-map/{id}', [ChatBotController::class, 'chatbotmap']);
Route::get('/chatbot/{id}/{groupId}', [ChatBotController::class, 'index'])->middleware(['auth:api', 'check.permission:View Chatbot']);
Route::post('/exist-sr-no', [ChatBotController::class, 'chekExistSerialNumber'])->middleware(['auth:api', 'check.permission:View Chatbot']);
Route::get('/chatbots/keywords/{userId}', [ChatBotController::class, 'getKeywordsByUser'])->middleware(['auth:api']);
Route::get('/edit-chatbot/{id}', [ChatBotController::class, 'edit'])->middleware(['auth:api', 'check.permission:View Chatbot']);
Route::post('/update-chatbot', [ChatBotController::class, 'update'])->middleware(['auth:api', 'check.permission:View Chatbot']);
Route::post('/chatbot-create', [ChatBotController::class, 'store'])->middleware(['auth:api', 'check.permission:View Chatbot']);
Route::delete('/chatbot-delete/{id}', [ChatBotController::class, 'destroy'])->middleware(['auth:api', 'check.permission:View Chatbot']);
Route::post('/store-ideal-timer', [ChatBotController::class, 'storeIdealTimer'])->middleware(['auth:api']);
Route::get('/get-ideal-timer-by-user/{userId}', [ChatBotController::class, 'getIdealTimerById'])->middleware(['auth:api']);
Route::post('/chatbot/copy-requests', [ChatBotController::class, 'copyRequests'])->middleware(['auth:api']);
Route::get('/idle-user-session', [ChatBotController::class, 'IdleUserSession']);
Route::get('/chatbot-memory-verify', [ChatBotController::class, 'chatbotMemoryDataVerify']);
Route::get('/active-user-session', [ChatBotController::class, 'ActiveUserIdleSession']);
Route::post('rearrangeSerialNumbers', [ChatBotController::class, 'rearrangeSerialNumbers']);

// ─── Chatbot Auth ─────────────────────────────────────────────────────────────
Route::post('/chatbot-auth-create', [ChatbotAuthController::class, 'store'])->middleware(['auth:api', 'check.permission:View Chatbot']);
Route::get('/chatbot-auth-edit/{id}', [ChatbotAuthController::class, 'edit'])->middleware(['auth:api', 'check.permission:View Chatbot']);
Route::post('/chatbot-auth-update', [ChatbotAuthController::class, 'update'])->middleware(['auth:api', 'check.permission:View Chatbot']);

// ─── Chatbot Groups ───────────────────────────────────────────────────────────
Route::post('/chatbot-groups', [ChatBotController::class, 'createChatbotGroup'])->middleware(['auth:api']);
Route::get('/chatbot-groups/{id}', [ChatBotController::class, 'getChatbotGroups'])->middleware(['auth:api']);
Route::post('/chatbot-groups/{id}', [ChatBotController::class, 'updateChatbotGroup'])->middleware(['auth:api']);
Route::post('/chatbot-groups-status', [ChatBotController::class, 'updateGroupStatus'])->middleware(['auth:api']);
Route::delete('chatbot-group/{groupId}', [ChatBotController::class, 'deleteChatbotGroup'])->middleware(['auth:api']);

// ─── Default Chatbot ──────────────────────────────────────────────────────────
Route::get('default-chatbot/{id}', [DefaultChatbotController::class, 'index'])->middleware(['auth:api']);
Route::post('update-default-chatbot', [DefaultChatbotController::class, 'create'])->middleware(['auth:api']);

// ─── Support Agents ───────────────────────────────────────────────────────────
Route::get('/support-agents', [SupportAgentController::class, 'index'])->middleware(['auth:api']);
Route::post('/support-agents', [SupportAgentController::class, 'create'])->middleware(['auth:api']);
Route::put('/update-agents/{id}', [SupportAgentController::class, 'update'])->middleware(['auth:api']);
Route::post('/support-agent/{id}/status', [SupportAgentController::class, 'toggleOnlineStatus'])->middleware(['auth:api']);
Route::get('/user/support-agents', [SupportAgentController::class, 'getSupportAgents'])->middleware(['auth:api']);
Route::post('/support-agent/report/{id}', [SupportAgentController::class, 'updateReport'])->middleware(['auth:api']);
Route::delete('/support-agent/{id}', [SupportAgentController::class, 'destroy'])->middleware(['auth:api']);
