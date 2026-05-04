<?php

use App\Http\Controllers\Contact\ContactController;
use App\Http\Controllers\Contact\GroupController;
use Illuminate\Support\Facades\Route;

// ─── Groups ───────────────────────────────────────────────────────────────────
Route::get('groups/{id}', [GroupController::class, 'index'])->middleware(['auth:api', 'check.permission:View Group']);
Route::get('edit-group/{id}', [GroupController::class, 'show'])->middleware(['auth:api', 'check.permission:View Group']);
Route::post('create-group', [GroupController::class, 'store'])->middleware(['auth:api', 'check.permission:View Group']);
Route::get('manage-group/{id}', [GroupController::class, 'edit'])->middleware(['auth:api', 'check.permission:View Group']);
Route::delete('delete-group/{id}', [GroupController::class, 'destroy'])->middleware(['auth:api', 'check.permission:View Group']);

// ─── Contacts ─────────────────────────────────────────────────────────────────
Route::post('import-group/{id}', [ContactController::class, 'ImportContact'])->middleware(['auth:api', 'check.permission:View Group']);
Route::post('create-contact/{id}', [ContactController::class, 'store'])->middleware(['auth:api', 'check.permission:View Group']);
Route::delete('delete-contact/{id}', [ContactController::class, 'destroy'])->middleware(['auth:api', 'check.permission:View Group']);
Route::delete('multi-delete-contacts', [ContactController::class, 'destroyMultiple'])->middleware(['auth:api', 'check.permission:View Group']);
