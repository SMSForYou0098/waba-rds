<?php

use App\Http\Controllers\Template\EmailTemplateController;
use App\Http\Controllers\Template\CrouselPresetController;
use App\Http\Controllers\Template\ListMessagePresetController;
use Illuminate\Support\Facades\Route;

// ─── Email Templates ──────────────────────────────────────────────────────────
Route::get('/email-templates/{id}', [EmailTemplateController::class, 'index'])->middleware(['auth:api']);
Route::post('/store-templates', [EmailTemplateController::class, 'store'])->middleware(['auth:api']);
Route::post('/update-templates', [EmailTemplateController::class, 'update'])->middleware(['auth:api']);
Route::delete('email-templates/{id}', [EmailTemplateController::class, 'destroy'])->middleware(['auth:api']);
Route::post('/send-email/{id}', [EmailTemplateController::class, 'send'])->name('send-email');

// ─── Carousel Presets ─────────────────────────────────────────────────────────
Route::get('/presets/{id}', [CrouselPresetController::class, 'index']);
Route::get('/run-preset', [CrouselPresetController::class, 'quickSend']);
Route::post('/save-preset', [CrouselPresetController::class, 'store']);
Route::delete('crousel-preset/{id}', [CrouselPresetController::class, 'destroy'])->middleware(['auth:api']);
Route::post('/update-preset', [CrouselPresetController::class, 'update'])->middleware(['auth:api']);

// ─── List Message Presets ─────────────────────────────────────────────────────
Route::prefix('list-message-preset')->middleware('auth:api')->group(function () {
    Route::get('/{id}', [ListMessagePresetController::class, 'index']);
    Route::post('/', [ListMessagePresetController::class, 'store']);
    Route::get('/edit/{id}', [ListMessagePresetController::class, 'show']);
    Route::put('{id}', [ListMessagePresetController::class, 'update']);
    Route::delete('{id}', [ListMessagePresetController::class, 'destroy']);
});
