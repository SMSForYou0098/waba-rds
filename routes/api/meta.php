<?php

use App\Http\Controllers\Messaging\OnboardingController;
use App\Http\Controllers\Meta\MetaProxyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Meta Graph API Proxy Routes
|--------------------------------------------------------------------------
|
| All routes here are authenticated (auth:api — Passport Bearer token).
| Credentials (wa_token, phone_id, waba_id) are ALWAYS resolved from the
| authenticated user's DB config — never accepted from the request body.
|
| Frontend replaces direct graph.facebook.com calls with these routes.
|
*/

Route::prefix('messaging')->middleware(['auth:api'])->group(function () {

    // ── Phase 1: Messages ──────────────────────────────────────────────────────
    // Throttled separately: 60 requests/min per user to prevent abuse
    Route::post('send', [MetaProxyController::class, 'send'])
        ->middleware('throttle:60,1');

    // ── Phase 1: Templates ─────────────────────────────────────────────────────
    Route::get('templates', [MetaProxyController::class, 'getTemplates']);
    Route::post('templates', [MetaProxyController::class, 'createTemplate']);
    Route::delete('templates/{name}', [MetaProxyController::class, 'deleteTemplate']);

    // ── Phase 1: OAuth Connect (App Secret stays server-side) ─────────────────
    Route::post('connect', [MetaProxyController::class, 'connect']);

    // ── Embedded signup: strict sequential steps 0–4 in one request ─────────
    Route::post('onboarding/complete', [OnboardingController::class, 'complete']);

    // ── Phase 2: Media ─────────────────────────────────────────────────────────
    Route::post('media/upload', [MetaProxyController::class, 'uploadMedia']);
    Route::get('media/{mediaId}', [MetaProxyController::class, 'getMedia']);

    // ── Phase 2: Flows ─────────────────────────────────────────────────────────
    Route::get('flows', [MetaProxyController::class, 'getFlows']);
    Route::post('flows', [MetaProxyController::class, 'createFlow']);
    Route::post('flows/{flowId}/publish', [MetaProxyController::class, 'publishFlow']);
    Route::delete('flows/{flowId}', [MetaProxyController::class, 'deleteFlow']);

    // ── Phase 3: Account ───────────────────────────────────────────────────────
    Route::get('phone-numbers', [MetaProxyController::class, 'phoneNumbers']);
});
