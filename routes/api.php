<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ExternalApiController;

/*
|--------------------------------------------------------------------------
| External API Routes (no CSRF, no session — Bearer token auth only)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->middleware(\App\Http\Middleware\VerifyApiKey::class)->group(function () {
    Route::get('/models', [ExternalApiController::class, 'models']);
    Route::post('/chat/completions', [ExternalApiController::class, 'chatCompletions']);
    Route::post('/messages', [ExternalApiController::class, 'messages']);
});
