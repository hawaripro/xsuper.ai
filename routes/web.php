<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminStatsController;
use App\Http\Controllers\Api\AiCatalogController;
use App\Http\Controllers\Api\AiProviderController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AudioController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\DashboardController as ApiDashboardController;
use App\Http\Controllers\Api\DashboardSearchController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\MediaToolController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PeriodController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\PublicSiteController;
use App\Services\AiProxyService;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require __DIR__.'/channels.php';

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
    Route::post('/referrals/capture', [ReferralController::class, 'capture']);
    Route::post('/analytics/events', [AnalyticsController::class, 'store'])->middleware('auth');

    // Protected routes — track device on ALL authenticated requests
    Route::middleware(['auth', 'track.device'])->group(function () {
        Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate']);

        // Profile
        Route::get('/u/me', function (Request $request) {
            $u = $request->user();

            return response()->json(['name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'created_at' => $u->created_at]);
        });
        Route::put('/u/p', [ProfileController::class, 'update']);
        Route::put('/u/pw', [ProfileController::class, 'updatePassword']);

        // Member control center
        Route::get('/dashboard', [ApiDashboardController::class, 'show']);
        Route::get('/dashboard/search', [DashboardSearchController::class, 'show'])->middleware('throttle:120,1');
        Route::get('/content/announcement', [ContentController::class, 'announcement']);
        Route::get('/usage/me', [ApiDashboardController::class, 'usage']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('/realtime/config', [RealtimeController::class, 'show']);
        Route::get('/library', [LibraryController::class, 'index']);

        // Feedback and support
        Route::get('/feedback', [FeedbackController::class, 'index']);
        Route::post('/feedback', [FeedbackController::class, 'store']);
        Route::get('/support/tickets', [SupportController::class, 'index']);
        Route::post('/support/tickets', [SupportController::class, 'store']);
        Route::get('/support/tickets/{ticket}', [SupportController::class, 'show']);
        Route::post('/support/tickets/{ticket}/replies', [SupportController::class, 'reply']);
        // Chat (check expiry)
        Route::middleware('check.expiry')->group(function () {
            Route::get('/c/m', [ChatController::class, 'models']);
            Route::get('/c/am', [ChatController::class, 'allModels']);
            Route::post('/c/s', [ChatController::class, 'send']);
            Route::get('/c/h', [ChatController::class, 'history']);
            Route::get('/c/h/{conversationId}', [ChatController::class, 'conversation']);
            Route::delete('/c/h/{conversationId}', [ChatController::class, 'deleteConversation']);
        });

        // Video and image generation
        Route::post('/v/{jobId}/cancel', [VideoController::class, 'cancel']);
        Route::get('/v/{jobId}/reference', [VideoController::class, 'reference']);
        Route::middleware('check.expiry')->group(function () {
            Route::get('/v/models', [VideoController::class, 'models']);
            Route::post('/v/gen', [VideoController::class, 'generate']);
            Route::get('/v/history', [VideoController::class, 'history']);
            Route::get('/v/status/{jobId}', [VideoController::class, 'status']);
            Route::get('/v/{jobId}/asset', [VideoController::class, 'asset']);

            Route::get('/images/models', [ImageController::class, 'models']);
            Route::post('/images', [ImageController::class, 'generate']);
            Route::get('/images', [ImageController::class, 'history']);
            Route::get('/images/{jobId}/assets/{index}', [ImageController::class, 'asset'])->whereNumber('index');
            Route::get('/images/{jobId}', [ImageController::class, 'show']);
        });

        // Existing outputs and cancellation remain recoverable after subscription expiry.
        Route::get('/audio/models', [AudioController::class, 'models'])->middleware('check.expiry');
        Route::post('/audio', [AudioController::class, 'generate'])->middleware(['check.expiry', 'throttle:10,1']);
        Route::get('/audio', [AudioController::class, 'history']);
        Route::get('/audio/{jobId}', [AudioController::class, 'show']);
        Route::post('/audio/{jobId}/cancel', [AudioController::class, 'cancel']);
        Route::get('/audio/{jobId}/asset', [AudioController::class, 'asset']);

        Route::get('/media-tools/capabilities', [MediaToolController::class, 'capabilities']);
        Route::get('/media-tools', [MediaToolController::class, 'history']);
        Route::delete('/media-tools', [MediaToolController::class, 'destroyAll']);
        Route::post('/media-tools/inspect', [MediaToolController::class, 'inspect'])->middleware(['check.expiry', 'throttle:20,1']);
        Route::post('/media-tools/download', [MediaToolController::class, 'download'])->middleware(['check.expiry', 'throttle:10,1']);
        Route::post('/media-tools/convert', [MediaToolController::class, 'convert'])->middleware(['check.expiry', 'throttle:10,1']);
        Route::get('/media-tools/{jobId}', [MediaToolController::class, 'show']);
        Route::delete('/media-tools/{jobId}', [MediaToolController::class, 'destroy']);
        Route::post('/media-tools/{jobId}/cancel', [MediaToolController::class, 'cancel']);
        Route::get('/media-tools/{jobId}/asset', [MediaToolController::class, 'asset']);

        // Token
        Route::get('/t/balance', [TokenController::class, 'balance']);
        Route::get('/t/history', [TokenController::class, 'history']);
        Route::get('/pricing/wallet', [PricingController::class, 'wallet']);
        Route::get('/period/packages', [PeriodController::class, 'packages']);
        Route::get('/pricing/catalog', [PricingController::class, 'catalog']);

        Route::get('/deposits/catalog', [DepositController::class, 'catalog']);
        Route::post('/deposits/checkout', [DepositController::class, 'checkout']);
        Route::post('/deposits', [DepositController::class, 'store']);
        Route::get('/deposits', [DepositController::class, 'index']);
        Route::get('/deposits/{depositOrder}', [DepositController::class, 'show']);

        Route::get('/referrals/me', [ReferralController::class, 'memberStats']);
        // Admin
        Route::middleware('admin')->group(function () {
            // Admin: topup tokens
            Route::post('/t/topup', [TokenController::class, 'topup']);
            Route::get('/pricing/settings', [PricingController::class, 'index']);
            Route::patch('/pricing/durations/bulk', [PricingController::class, 'bulkSaveDurations']);
            Route::patch('/pricing/rates/bulk', [PricingController::class, 'bulkUpdateUsageRates']);
            Route::delete('/pricing/rates/bulk', [PricingController::class, 'bulkDestroyUsageRates']);
            Route::put('/pricing/durations/{package}', [PricingController::class, 'saveDuration']);
            Route::post('/pricing/rates', [PricingController::class, 'saveUsageRate']);
            Route::put('/pricing/rates/{usageRate}', [PricingController::class, 'saveUsageRate']);
            Route::delete('/pricing/rates/{usageRate}', [PricingController::class, 'destroyUsageRate']);
            Route::post('/pricing/wallet/topup', [PricingController::class, 'topupWallet']);
            Route::get('/admin/deposits', [DepositController::class, 'adminIndex']);
            Route::post('/admin/deposits/{depositOrder}/approve', [DepositController::class, 'approve']);
            Route::post('/admin/deposits/{depositOrder}/reject', [DepositController::class, 'reject']);
            Route::get('/admin/token-packages', [DepositController::class, 'adminCatalog']);
            Route::patch('/admin/token-packages/{tokenPackage}', [DepositController::class, 'updateTokenPackage']);

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

            // Content and support operations
            Route::get('/admin/feedback', [FeedbackController::class, 'adminIndex']);
            Route::patch('/admin/feedback/{feedback}', [FeedbackController::class, 'moderate']);
            Route::get('/admin/support/tickets', [SupportController::class, 'adminIndex']);
            Route::get('/admin/support/tickets/{ticket}', [SupportController::class, 'show']);
            Route::patch('/admin/support/tickets/{ticket}', [SupportController::class, 'update']);
            Route::post('/admin/support/tickets/{ticket}/replies', [SupportController::class, 'reply']);
            Route::post('/admin/engagement/broadcasts', [EngagementController::class, 'broadcast']);
            Route::get('/admin/referrals', [ReferralController::class, 'adminStatus']);
            Route::put('/admin/referrals/configuration', [ReferralController::class, 'updateConfiguration']);

            // Content and operational intelligence
            Route::get('/admin/content', [ContentController::class, 'index']);
            Route::post('/admin/content', [ContentController::class, 'store']);
            Route::put('/admin/content/{contentBlock}', [ContentController::class, 'update']);
            Route::post('/admin/content/{contentBlock}/publish', [ContentController::class, 'publish']);
            Route::post('/admin/content/{contentBlock}/unpublish', [ContentController::class, 'unpublish']);
            Route::get('/admin/analytics/funnel', [AnalyticsController::class, 'funnel']);
            Route::get('/admin/audit', [AuditController::class, 'index']);

            // AI catalog and media operations
            Route::get('/admin/ai/catalog', [AiCatalogController::class, 'index']);
            Route::post('/admin/ai/providers', [AiProviderController::class, 'store']);
            Route::patch('/admin/ai/providers/{provider}', [AiProviderController::class, 'update']);
            Route::delete('/admin/ai/providers/{provider}', [AiProviderController::class, 'destroy']);
            Route::post('/admin/ai/providers/{provider}/check', [AiProviderController::class, 'check']);
            Route::post('/admin/ai/providers/{provider}/sync', [AiCatalogController::class, 'sync']);
            Route::post('/admin/ai/models', [AiCatalogController::class, 'storeModel']);
            Route::patch('/admin/ai/models/bulk', [AiCatalogController::class, 'bulkUpdateModels']);
            Route::delete('/admin/ai/models/bulk', [AiCatalogController::class, 'bulkDestroyModels']);
            Route::patch('/admin/ai/models/{model}', [AiCatalogController::class, 'updateModel']);
            Route::get('/admin/media/queue', [ImageController::class, 'adminQueue']);
        });

        // Member: duration orders (authenticated, not admin-only)
        Route::get('/period/packages', [PeriodController::class, 'packages']);
        Route::post('/period/checkout', [PeriodController::class, 'checkout']);
        Route::post('/period/order', [PeriodController::class, 'store']);
        Route::get('/period/my-orders', [PeriodController::class, 'myOrders']);

        // Onboarding & Templates
        Route::get('/onboarding/status', [OnboardingController::class, 'status']);
        Route::post('/onboarding/mode', [OnboardingController::class, 'saveMode']);
        Route::get('/templates', [OnboardingController::class, 'templates']);
    });

    Route::any('/{path}', fn () => response()->json(['message' => 'API endpoint tidak ditemukan.'], 404))->where('path', '.*');
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

// Authenticated dashboard mirrors the public locale structure under /en.
// Single wildcard keeps future nested SPA routes reachable; public pages
// above are registered first and win on exact match.
Route::view('/en/{path?}', 'app')
    ->where('path', '(?!pricing$|models$|privacy-policy$|terms-of-service$|refund-policy$).*');
Route::get('/sitemap.xml', [PublicSiteController::class, 'sitemap'])->name('sitemap');

// SPA catch-all — must be last
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
