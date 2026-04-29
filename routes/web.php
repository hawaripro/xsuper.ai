<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\Api\TokenController;

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
        Route::get('/u/me', function (Request $request) {
            $u = $request->user();
            return response()->json(['name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'created_at' => $u->created_at]);
        });
        Route::put('/u/p', [ProfileController::class, 'update']);
        Route::put('/u/pw', [ProfileController::class, 'updatePassword']);

        // Chat (check expiry)
        Route::middleware('check.expiry')->group(function () {
            Route::get('/c/m', [ChatController::class, 'models']);
            Route::post('/c/s', [ChatController::class, 'send']);
            Route::get('/c/h', [ChatController::class, 'history']);
            Route::get('/c/h/{conversationId}', [ChatController::class, 'conversation']);
            Route::delete('/c/h/{conversationId}', [ChatController::class, 'deleteConversation']);
        });

        // Video Generator (check expiry)
        Route::middleware('check.expiry')->group(function () {
            Route::get('/v/models', [VideoController::class, 'models']);
            Route::post('/v/gen', [VideoController::class, 'generate']);
            Route::get('/v/history', [VideoController::class, 'history']);
            Route::get('/v/status/{jobId}', [VideoController::class, 'status']);
        });

        // Token
        Route::get('/t/balance', [TokenController::class, 'balance']);
        Route::get('/t/history', [TokenController::class, 'history']);

        // Admin
        Route::middleware('admin')->group(function () {
            // Admin: topup tokens
            Route::post('/t/topup', [TokenController::class, 'topup']);
            // AI Status (admin only)
            Route::get('/s/info', function () {
                $aiProxy = app(\App\Services\AiProxyService::class);
                return response()->json($aiProxy->getStatus());
            });

            Route::get('/a/u', [AdminController::class, 'index']);
            Route::post('/a/u', [AdminController::class, 'store']);
            Route::put('/a/u/{user}', [AdminController::class, 'update']);
            Route::delete('/a/u/{user}', [AdminController::class, 'destroy']);
        });

    });
});

// SPA catch-all — must be last
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
