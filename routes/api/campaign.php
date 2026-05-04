<?php

use App\Http\Controllers\Campaign\CampaignController;
use App\Http\Controllers\Campaign\ScheduleCampaignController;
use Illuminate\Support\Facades\Route;

// ─── Campaigns ────────────────────────────────────────────────────────────────
Route::post('/campaign-report/bulk-update', [CampaignController::class, 'BulkUpdateCampaignReport']);
Route::post('execute/{id}', [CampaignController::class, 'ExecuteCampaign']);
Route::post('/campaign-report/update', [CampaignController::class, 'updateCampaignReport']);
Route::get('campaign-reports/{id}', [CampaignController::class, 'CampaignReportData'])->middleware(['auth:api', 'check.permission:View Campaign']);
Route::get('campaigns/{id}', [CampaignController::class, 'index'])->middleware(['auth:api', 'check.permission:View Campaign']);
Route::post('create-campaign', [CampaignController::class, 'create'])->middleware(['auth:api', 'check.permission:View Campaign']);
Route::post('create-campaign-report', [CampaignController::class, 'CampaignReport'])->middleware(['auth:api', 'check.permission:View Campaign']);
Route::post('flow/flow-data', [CampaignController::class, 'FlowData']);

Route::prefix('campaigns')->group(function () {
    Route::post('/process', [CampaignController::class, 'processCampaign']);
    Route::post('/process-all', [CampaignController::class, 'processAllCampaigns']);
});

// ─── Scheduled Campaigns ──────────────────────────────────────────────────────
Route::get('get-schedule-campaign/{id}', [ScheduleCampaignController::class, 'index'])->middleware(['auth:api', 'check.permission:View Campaign']);
Route::get('get-schedule-campaign-report/{id}', [ScheduleCampaignController::class, 'CampaignReportData'])->middleware(['auth:api', 'check.permission:View Campaign']);
Route::post('schedule-campaign/{id}', [ScheduleCampaignController::class, 'store'])->middleware(['auth:api', 'check.permission:View Campaign']);
Route::post('reschedule-campaign/{id}', [ScheduleCampaignController::class, 'reschedule'])->middleware(['auth:api', 'check.permission:View Campaign']);
Route::post('change-status-sc/{id}', [ScheduleCampaignController::class, 'handleStatus'])->middleware(['auth:api', 'check.permission:View Campaign']);
Route::delete('delete-schedule-campaign/{id}', [ScheduleCampaignController::class, 'destroy'])->middleware(['auth:api', 'check.permission:View Campaign']);
