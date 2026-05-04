<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\DataCleanupController;
use Illuminate\Support\Facades\Route;

// ─── Dashboard ────────────────────────────────────────────────────────────────
Route::get('dashboard-weekly-report/{id}', [DashboardController::class, 'weeklyReport'])->middleware(['auth:api']);
Route::get('dashboard-data-count/{id}', [DashboardController::class, 'DigitCounts'])->middleware(['auth:api']);
Route::get('dashboardData/{id}', [DashboardController::class, 'weeklyReport'])->middleware(['auth:api']);

// ─── Data Cleanup ─────────────────────────────────────────────────────────────
Route::prefix('cleanup')->middleware('auth:api')->group(function () {
    // Reports
    Route::get('reports/export', [DataCleanupController::class, 'exportReports']);
    Route::delete('reports', [DataCleanupController::class, 'deleteReports']);

    // OutReports
    Route::get('outreports/export', [DataCleanupController::class, 'exportOutReports']);
    Route::delete('outreports', [DataCleanupController::class, 'deleteOutReports']);

    // Campaigns and their reports
    Route::get('campaigns/export', [DataCleanupController::class, 'exportCampaigns']);
    Route::get('campaign-reports/export', [DataCleanupController::class, 'exportCampaignReports']);
    Route::delete('campaigns', [DataCleanupController::class, 'deleteCampaigns']);

    // Records and download routes
    Route::get('/records', [DataCleanupController::class, 'getCleanupRecords']);
    Route::get('/download/{id}', [DataCleanupController::class, 'downloadExport']);
    Route::post('delete-cleanup-records', [DataCleanupController::class, 'deleteCleanupRecords']);
});
