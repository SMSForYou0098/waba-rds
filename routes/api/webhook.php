<?php

use App\Http\Controllers\Webhook\WebhookController;
use Illuminate\Support\Facades\Route;

// ─── Webhook ──────────────────────────────────────────────────────────────────
Route::get('/webhook', [WebhookController::class, 'handle']);
Route::post('/webhook', [WebhookController::class, 'Webhook']);
Route::post('/webhookData', [WebhookController::class, 'handleWebhook']);
