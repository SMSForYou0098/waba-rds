<?php

use App\Http\Controllers\Report\ReportHandler;
use App\Http\Controllers\Report\GlobalReportsController;
use App\Http\Controllers\Report\ExportHandleController;
use Illuminate\Support\Facades\Route;

// ─── Incoming / Outgoing Reports ──────────────────────────────────────────────
Route::get('/reports/{id}', [ReportHandler::class, 'Reports'])->middleware(['auth:api', 'check.permission:View Incoming Reports']);
Route::post('/out-reports/create', [ReportHandler::class, 'MakeOutReport'])->middleware(['auth:api']);
Route::get('/out-reports/{id}', [ReportHandler::class, 'OutReports'])->middleware(['auth:api', 'check.permission:View Outgoing Reports']);
Route::get('/live-status', [ReportHandler::class, 'LiveStatus'])->middleware(['auth:api']);
Route::post('/make-reports', [ReportHandler::class, 'MakeOutReport'])->middleware(['auth:api']);
Route::put('/reports/{id}/update-media-url', [ReportHandler::class, 'updateMediaUrl'])->middleware(['auth:api']);

// ─── Badges ───────────────────────────────────────────────────────────────────
Route::post('/badges', [ReportHandler::class, 'storeBadges'])->middleware(['auth:api']);
Route::post('/reports/{reportId}/badges', [ReportHandler::class, 'assignBadgesToReport'])->middleware(['auth:api']);
Route::post('/badges/{badgeId}/reports', [ReportHandler::class, 'assignReportsToBadge'])->middleware(['auth:api']);
Route::get('/user/{userId}/badges', [ReportHandler::class, 'getUserBadges'])->middleware(['auth:api']);
Route::delete('badges/{badgeId}', [ReportHandler::class, 'deleteBadge'])->middleware(['auth:api']);

// ─── Global Reports ───────────────────────────────────────────────────────────
Route::get('/global-reports-fast', [GlobalReportsController::class, 'getCombinedReportsFast'])->middleware(['auth:api']);

// ─── Exports ──────────────────────────────────────────────────────────────────
Route::get('/export-reports/{id}', [ExportHandleController::class, 'ExportReport'])->middleware(['auth:api']);
Route::get('/export-out-reports/{id}', [ExportHandleController::class, 'ExportOutReport'])->middleware(['auth:api']);
Route::get('/export-cost-analysis/{id}', [ExportHandleController::class, 'ExportCostReport'])->middleware(['auth:api']);
Route::get('/export-campaign/{id}', [ExportHandleController::class, 'ExportCampaignReport'])->middleware(['auth:api']);
Route::get('/export-campaign-contacts/{id}', [ExportHandleController::class, 'ExportCampaignContact'])->middleware(['auth:api']);
Route::get('/export-template/{id}', [ExportHandleController::class, 'ExportTemplateReport'])->middleware(['auth:api']);
Route::get('/export-group/{id}', [ExportHandleController::class, 'ExportGroupReport'])->middleware(['auth:api']);
Route::get('/export-group-contacts/{id}', [ExportHandleController::class, 'ExportGroupContactReport'])->middleware(['auth:api']);
Route::get('/export-users/{id}', [ExportHandleController::class, 'ExportUsers'])->middleware(['auth:api']);
Route::get('/export-credit-history/{id}', [ExportHandleController::class, 'ExportCreditHistory'])->middleware(['auth:api']);
