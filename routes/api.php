<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ExternalApiController;
use App\Http\Controllers\Api\MediaApiController;

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

Route::get('/v1/media/generations/{id}/outputs/{outputId}/signed', [MediaApiController::class, 'signedDownload'])
    ->middleware('signed')->name('api.media.outputs.signed');

Route::prefix('v1')->middleware(\App\Http\Middleware\VerifyApiKey::class)->group(function () {
    Route::get('/media/models', [MediaApiController::class, 'models']);
    Route::get('/media/models/{model}', [MediaApiController::class, 'model'])->where('model', '.*');
    Route::get('/media/generations', [MediaApiController::class, 'index']);
    Route::get('/media/generations/{id}', [MediaApiController::class, 'show'])->name('api.media.generations.show');
    Route::post('/media/generations/{id}/cancel', [MediaApiController::class, 'cancel']);
    Route::get('/media/generations/{id}/outputs/{outputId}', [MediaApiController::class, 'download'])->name('api.media.outputs');
    Route::middleware('throttle:media-api-create')->group(function () {
        Route::post('/media/generations', [MediaApiController::class, 'store']);
        Route::post('/files', [MediaApiController::class, 'upload']);
        Route::post('/images/generations', [MediaApiController::class, 'images']);
    });
});
