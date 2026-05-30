<?php

use App\Http\Controllers\Chat\ChatbotFlowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'check.permission:View Chatbot'])->group(function () {
    Route::get('/chatbot-flows', [ChatbotFlowController::class, 'index']);
    Route::post('/chatbot-flows', [ChatbotFlowController::class, 'store']);
    Route::get('/chatbot-flows/groups/{groupId}/active', [ChatbotFlowController::class, 'activeForGroup']);
    Route::get('/chatbot-flows/{flow}', [ChatbotFlowController::class, 'show']);
    Route::put('/chatbot-flows/{flow}', [ChatbotFlowController::class, 'update']);
    Route::patch('/chatbot-flows/{flow}', [ChatbotFlowController::class, 'patch']);
    Route::delete('/chatbot-flows/{flow}', [ChatbotFlowController::class, 'destroy']);
    Route::post('/chatbot-flows/{flow}/duplicate', [ChatbotFlowController::class, 'duplicate']);
    Route::post('/chatbot-flows/{flow}/publish', [ChatbotFlowController::class, 'publish']);
    Route::post('/chatbot-flows/{flow}/unpublish', [ChatbotFlowController::class, 'unpublish']);
    Route::post('/chatbot-flows/{flow}/validate', [ChatbotFlowController::class, 'validateDefinition']);
    Route::post('/chatbot-flows/{flow}/simulate', [ChatbotFlowController::class, 'simulate']);
    Route::get('/chatbot-flows/{flow}/sessions', [ChatbotFlowController::class, 'sessions']);
    Route::delete('/chatbot-flows/sessions/{session}', [ChatbotFlowController::class, 'resetSession']);
});
