<?php

use App\Http\Controllers\Billing\BalanceController;
use App\Http\Controllers\Billing\PricingModelController;
use App\Http\Controllers\Billing\PlanController;
use App\Http\Controllers\Billing\PlanFeatureController;
use App\Http\Controllers\Billing\PlanPurchaseController;
use App\Http\Controllers\Billing\RefundCheckController;
use Illuminate\Support\Facades\Route;

// ─── Balance ──────────────────────────────────────────────────────────────────
Route::get('balance-history/{id}', [BalanceController::class, 'index'])->middleware(['auth:api', 'check.permission:View Payment History']);
Route::post('add-balance', [BalanceController::class, 'create'])->middleware(['auth:api']);

// ─── Pricing Model ────────────────────────────────────────────────────────────
Route::get('pricing-history/{id}', [PricingModelController::class, 'index'])->middleware(['auth:api', 'check.permission:View Pricing Model']);
Route::post('pricing-model', [PricingModelController::class, 'create'])->middleware(['auth:api']);

// ─── Plans ────────────────────────────────────────────────────────────────────
Route::get('/plans', [PlanController::class, 'index']);
Route::prefix('plans')->middleware('auth:api')->group(function () {
    Route::post('/', [PlanController::class, 'store']);
    Route::get('/{id}', [PlanController::class, 'show']);
    Route::put('/{id}', [PlanController::class, 'update']);
    Route::delete('/{id}', [PlanController::class, 'destroy']);
    Route::post('/{planId}/config', [PlanController::class, 'storeConfig']);
    Route::get('/{planId}/config', [PlanController::class, 'getConfig']);
    Route::post('/purchase', [PlanPurchaseController::class, 'purchase']);
});

// ─── Plan Features ────────────────────────────────────────────────────────────
Route::prefix('plan-features')->middleware('auth:api')->group(function () {
    Route::get('/', [PlanFeatureController::class, 'index']);
    Route::post('/', [PlanFeatureController::class, 'store']);
    Route::get('/{id}', [PlanFeatureController::class, 'show']);
    Route::post('/{id}', [PlanFeatureController::class, 'update']);
    Route::delete('/{id}', [PlanFeatureController::class, 'destroy']);
    Route::get('/{id}/plans', [PlanFeatureController::class, 'getPlans']);
});

// ─── Refunds ──────────────────────────────────────────────────────────────────
Route::get('/refund/check/{userId}', [RefundCheckController::class, 'checkRefund']);
Route::post('/refund/process/{userId}', [RefundCheckController::class, 'processRefund']);
