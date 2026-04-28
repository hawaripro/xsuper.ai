<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\AdminController;

/*
|--------------------------------------------------------------------------
| API Routes (session-based, under /api prefix)
|--------------------------------------------------------------------------
| Fortify handles: POST /login, POST /logout, POST /register
| Custom routes below handle the rest of the API
*/

Route::prefix('api')->middleware('web')->group(function () {
    // Get current authenticated user
    Route::get('/user', [AuthController::class, 'user']);

    // Auth routes (custom, Fortify handles /login /logout /register natively)
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');

    // Protected routes
    Route::middleware('auth')->group(function () {
        // Profile
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

        // Chat AI
        Route::get('/chat/models', [ChatController::class, 'models']);
        Route::post('/chat/send', [ChatController::class, 'send']);
        Route::get('/chat/history', [ChatController::class, 'history']);
        Route::get('/chat/history/{conversationId}', [ChatController::class, 'conversation']);
        Route::delete('/chat/history/{conversationId}', [ChatController::class, 'deleteConversation']);

        // AI Proxy Status
        Route::get('/ai/status', function () {
            $aiProxy = app(\App\Services\AiProxyService::class);
            return response()->json($aiProxy->getStatus());
        });

        // Admin
        Route::middleware('admin')->group(function () {
            Route::get('/admin/users', [AdminController::class, 'index']);
            Route::post('/admin/users', [AdminController::class, 'store']);
            Route::put('/admin/users/{user}', [AdminController::class, 'update']);
            Route::delete('/admin/users/{user}', [AdminController::class, 'destroy']);
        });
    });
});

// SPA catch-all — must be last
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
