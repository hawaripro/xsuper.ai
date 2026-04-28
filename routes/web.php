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
    // Health check (public — for UptimeRobot monitoring)
    Route::get('/health', function () {
        $status = ['status' => 'ok', 'timestamp' => now()->toISOString()];

        // Check database
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            $status['database'] = 'ok';
        } catch (\Exception $e) {
            $status['database'] = 'error';
            $status['status'] = 'degraded';
        }

        $code = $status['status'] === 'ok' ? 200 : 503;
        return response()->json($status, $code);
    });

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

        // Dash auth — verify admin & set cookie for dash.ultrai.id
        Route::middleware('admin')->get('/dash/verify', function (Request $request) {
            $token = hash('sha256', $request->user()->id . '|' . config('app.key') . '|dash');
            $cookie = cookie('dash_token', $token, 120, '/', '.ultrai.id', true, true, false, 'Lax');
            return response()->json(['status' => 'ok', 'redirect' => 'https://dash.ultrai.id'])->withCookie($cookie);
        });

        // Dash auth — Nginx calls this to verify the dash_token cookie
        Route::middleware('admin')->get('/dash/check', function (Request $request) {
            $token = $request->cookie('dash_token');
            $expected = hash('sha256', $request->user()->id . '|' . config('app.key') . '|dash');
            if ($token === $expected) {
                return response('OK', 200);
            }
            return response('Forbidden', 403);
        });
    });
});

// SPA catch-all — must be last
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
