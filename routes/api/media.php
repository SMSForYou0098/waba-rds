<?php

use App\Http\Controllers\Media\MediaController;
use App\Http\Controllers\Media\FacebookUploadController;
use Illuminate\Support\Facades\Route;

// ─── Media ────────────────────────────────────────────────────────────────────
Route::post('upload-media', [MediaController::class, 'store'])->middleware(['auth:api', 'check.permission:Upload Media']);
Route::get('/user/{id}/storage', [MediaController::class, 'getUserStorage']);
Route::get('media/{id}', [MediaController::class, 'index'])->middleware(['auth:api', 'check.permission:View Media']);
Route::get('media-restore/{id}/{mediaId}', [MediaController::class, 'restore'])->middleware(['auth:api']);
Route::delete('delete-media/{id}', [MediaController::class, 'destroy'])->middleware(['auth:api', 'check.permission:Delete Media']);
Route::delete('/media/permanent-delete/{id}', [MediaController::class, 'permanentDeleteFile'])->middleware(['auth:api']);
Route::get('/media/get-file/{id}', [MediaController::class, 'getFile'])->middleware(['auth:api']);
Route::post('/retrieve-image-from-meta', [MediaController::class, 'retrieveImageFromMeta'])->middleware(['auth:api']);
Route::get('/reports-media/files/{parent}/{child}', [MediaController::class, 'getFilesByParentAndChild']);
Route::post('/store-from-me-media', [MediaController::class, 'storeFromMeMedia'])->middleware(['auth:api']);

// ─── Facebook Upload Session ───────────────────────────────────────────────────
Route::post('/upload-session', [FacebookUploadController::class, 'getSessionId']);
