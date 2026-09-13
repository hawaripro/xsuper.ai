<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminStatsController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ChatProController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PeriodController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\PublicSiteController;
use App\Services\AiProxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

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
            DB::connection()->getPdo();
            $status['database'] = 'ok';
        } catch (Exception $e) {
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

    // Protected routes — track device on ALL authenticated requests
    Route::middleware(['auth', 'track.device'])->group(function () {
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
            Route::get('/c/am', [ChatController::class, 'allModels']);
            Route::post('/c/s', [ChatController::class, 'send']);
            Route::get('/c/h', [ChatController::class, 'history']);
            Route::get('/c/h/{conversationId}', [ChatController::class, 'conversation']);
            Route::delete('/c/h/{conversationId}', [ChatController::class, 'deleteConversation']);
        });

        // Video Generator (check expiry + permission)
        Route::middleware('check.expiry')->group(function () {
            Route::get('/v/models', [VideoController::class, 'models']);
            Route::post('/v/gen', [VideoController::class, 'generate']);
            Route::get('/v/history', [VideoController::class, 'history']);
            Route::get('/v/status/{jobId}', [VideoController::class, 'status']);
        });

        // Token
        Route::get('/t/balance', [TokenController::class, 'balance']);
        Route::get('/t/history', [TokenController::class, 'history']);
        Route::get('/pricing/wallet', [PricingController::class, 'wallet']);
        Route::get('/period/packages', [PeriodController::class, 'packages']);
        Route::get('/pricing/catalog', [PricingController::class, 'catalog']);

        // Admin
        Route::middleware('admin')->group(function () {
            // Admin: topup tokens
            Route::post('/t/topup', [TokenController::class, 'topup']);
            Route::get('/pricing/settings', [PricingController::class, 'index']);
            Route::put('/pricing/durations/{package}', [PricingController::class, 'saveDuration']);
            Route::post('/pricing/rates', [PricingController::class, 'saveUsageRate']);
            Route::put('/pricing/rates/{usageRate}', [PricingController::class, 'saveUsageRate']);
            Route::delete('/pricing/rates/{usageRate}', [PricingController::class, 'destroyUsageRate']);
            Route::post('/pricing/wallet/topup', [PricingController::class, 'topupWallet']);

            // Admin: usage stats
            Route::get('/usage', [UsageController::class, 'index']);

            // Admin: device management
            Route::get('/d/list', [DeviceController::class, 'index']);
            Route::put('/d/{device}', [DeviceController::class, 'update']);
            Route::delete('/d/{device}', [DeviceController::class, 'destroy']);

            // Admin: API key management
            Route::get('/k/list', [ApiKeyController::class, 'index']);
            Route::post('/k/create', [ApiKeyController::class, 'store']);
            Route::post('/k/toggle/{apiKey}', [ApiKeyController::class, 'toggle']);
            Route::post('/k/regen/{apiKey}', [ApiKeyController::class, 'regenerate']);
            Route::delete('/k/{apiKey}', [ApiKeyController::class, 'destroy']);
            // AI Status (admin only)
            Route::get('/s/info', function () {
                $aiProxy = app(AiProxyService::class);

                return response()->json($aiProxy->getStatus());
            });

            Route::get('/a/u', [AdminController::class, 'index']);
            Route::post('/a/u', [AdminController::class, 'store']);
            Route::put('/a/u/{user}', [AdminController::class, 'update']);
            Route::delete('/a/u/{user}', [AdminController::class, 'destroy']);

            // Chat AI Pro (OpenWebUI) session management
            Route::get('/a/chat-pro/users', [ChatProController::class, 'users']);
            Route::post('/a/chat-pro/logout/{userId}', [ChatProController::class, 'forceLogout']);
            Route::delete('/a/chat-pro/user/{userId}', [ChatProController::class, 'deleteUser']);

            // Period Management (admin)
            Route::get('/a/period', [PeriodController::class, 'index']);
            Route::post('/a/period/approve/{order}', [PeriodController::class, 'approve']);
            Route::post('/a/period/reject/{order}', [PeriodController::class, 'reject']);
            Route::delete('/a/period/{order}', [PeriodController::class, 'destroy']);
            Route::post('/a/period/add-duration', [PeriodController::class, 'addDuration']);

            // Admin Stats
            Route::get('/a/stats/revenue', [AdminStatsController::class, 'revenue']);
            Route::get('/a/stats/expiring', [AdminStatsController::class, 'expiringUsers']);
            Route::get('/a/stats/orders', [AdminStatsController::class, 'orders']);
        });

        // Member: duration orders (authenticated, not admin-only)
        Route::get('/period/packages', [PeriodController::class, 'packages']);
        Route::post('/period/order', [PeriodController::class, 'store']);
        Route::get('/period/my-orders', [PeriodController::class, 'myOrders']);

        // Onboarding & Templates
        Route::get('/onboarding/status', [OnboardingController::class, 'status']);
        Route::post('/onboarding/mode', [OnboardingController::class, 'saveMode']);
        Route::get('/templates', [OnboardingController::class, 'templates']);
    });
});

// Google OAuth routes
Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

// Public pages are rendered on the server; Indonesian is unprefixed and English uses /en.
Route::get('/', [PublicSiteController::class, 'home'])->defaults('locale', 'id')->name('home');
Route::get('/pricing', [PublicSiteController::class, 'pricing'])->defaults('locale', 'id')->name('pricing');
Route::get('/models', [PublicSiteController::class, 'models'])->defaults('locale', 'id')->name('models');
Route::get('/privacy-policy', fn (Request $request, PublicSiteController $controller) => $controller->policy($request, 'privacy-policy'))->defaults('locale', 'id')->name('privacy-policy');
Route::get('/terms-of-service', fn (Request $request, PublicSiteController $controller) => $controller->policy($request, 'terms-of-service'))->defaults('locale', 'id')->name('terms-of-service');
Route::get('/refund-policy', fn (Request $request, PublicSiteController $controller) => $controller->policy($request, 'refund-policy'))->defaults('locale', 'id')->name('refund-policy');
Route::prefix('en')->group(function () {
    Route::get('/', [PublicSiteController::class, 'home'])->defaults('locale', 'en')->name('en.home');
    Route::get('/pricing', [PublicSiteController::class, 'pricing'])->defaults('locale', 'en')->name('en.pricing');
    Route::get('/models', [PublicSiteController::class, 'models'])->defaults('locale', 'en')->name('en.models');
    Route::get('/privacy-policy', fn (Request $request, PublicSiteController $controller) => $controller->policy($request, 'privacy-policy'))->defaults('locale', 'en')->name('en.privacy-policy');
    Route::get('/terms-of-service', fn (Request $request, PublicSiteController $controller) => $controller->policy($request, 'terms-of-service'))->defaults('locale', 'en')->name('en.terms-of-service');
    Route::get('/refund-policy', fn (Request $request, PublicSiteController $controller) => $controller->policy($request, 'refund-policy'))->defaults('locale', 'en')->name('en.refund-policy');
});
Route::get('/sitemap.xml', [PublicSiteController::class, 'sitemap'])->name('sitemap');

// SPA catch-all — must be last
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
