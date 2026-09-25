<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminStatsController;
use App\Http\Controllers\Api\AiCatalogController;
use App\Http\Controllers\Api\AiProviderController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\MemberApiKeyController;
use App\Http\Controllers\Api\AudioController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AutoPricingController;
use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\ChatArtifactController;
use App\Http\Controllers\Api\ChatAttachmentController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ChatWorkspaceController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\DashboardController as ApiDashboardController;
use App\Http\Controllers\Api\DashboardSearchController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\MediaAssetController;
use App\Http\Controllers\Api\MediaCatalogController;
use App\Http\Controllers\Api\MediaToolController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PeriodController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Controllers\Api\RealtimeMediaController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\StorageUpgradeController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\ThreeDController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\Api\WorkspaceMediaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Middleware\EnsureEmailVerified;
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
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:api-login');
    Route::post('/login/two-factor', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:two-factor');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
    Route::post('/referrals/capture', [ReferralController::class, 'capture']);
    Route::post('/analytics/events', [AnalyticsController::class, 'store'])->middleware('auth');
    // Cookie-independent signed delivery so providers can fetch an owned reference asset.
    Route::get('/media/public/{asset}', [MediaAssetController::class, 'deliver'])->middleware('signed')->name('media.asset.deliver');

    // Protected routes — track device on ALL authenticated requests
    Route::middleware(['auth', 'track.device', EnsureEmailVerified::class, 'ensure.active'])->group(function () {
        Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate']);

        // Profile
        Route::get('/u/me', function (Request $request) {
            $u = $request->user();

            return response()->json(['name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'created_at' => $u->created_at]);
        });
        Route::put('/u/p', [ProfileController::class, 'update']);
        Route::put('/u/pw', [ProfileController::class, 'updatePassword']);
        Route::post('/u/verify-email/send', [ProfileController::class, 'sendEmailOtp'])->middleware('throttle:6,1');
        Route::post('/u/verify-email', [ProfileController::class, 'verifyEmailOtp'])->middleware('throttle:12,1');
        Route::get('/u/security', [ProfileController::class, 'security']);
        Route::post('/u/security/two-factor', [ProfileController::class, 'enableTwoFactor'])->middleware('throttle:10,1');
        Route::post('/u/security/two-factor/confirm', [ProfileController::class, 'confirmTwoFactor'])->middleware('throttle:10,1');
        Route::post('/u/security/two-factor/recovery-codes', [ProfileController::class, 'regenerateRecoveryCodes'])->middleware('throttle:10,1');
        Route::delete('/u/security/two-factor', [ProfileController::class, 'disableTwoFactor'])->middleware('throttle:10,1');

        // Member control center
        Route::get('/dashboard', [ApiDashboardController::class, 'show']);
        Route::get('/dashboard/search', [DashboardSearchController::class, 'show'])->middleware('throttle:120,1');
        Route::get('/content/announcement', [ContentController::class, 'announcement']);
        Route::get('/usage/me', [ApiDashboardController::class, 'usage']);
        Route::get('/me/api-keys', [MemberApiKeyController::class, 'index']);
        Route::post('/me/api-keys', [MemberApiKeyController::class, 'store']);
        Route::post('/me/api-keys/{id}/revoke', [MemberApiKeyController::class, 'revoke'])->whereNumber('id');
        Route::delete('/me/api-keys/{id}', [MemberApiKeyController::class, 'destroy'])->whereNumber('id');
        Route::get('/me/api-models', [MemberApiKeyController::class, 'models']);
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
        // Chat (feature permission)
        Route::middleware('feature.access')->group(function () {
            Route::get('/c/m', [ChatController::class, 'models']);
            Route::get('/c/am', [ChatController::class, 'allModels']);
            Route::post('/c/s', [ChatController::class, 'send']);
            Route::get('/c/capabilities', [ChatController::class, 'capabilities']);
            Route::get('/c/workspaces', [ChatWorkspaceController::class, 'index']);
            Route::post('/c/workspaces', [ChatWorkspaceController::class, 'store']);
            Route::patch('/c/workspaces/{workspace}', [ChatWorkspaceController::class, 'update'])->whereNumber('workspace');
            Route::post('/c/h', [ChatWorkspaceController::class, 'createConversation']);
            Route::get('/c/h', [ChatController::class, 'history']);
            Route::get('/c/h/{conversationId}', [ChatController::class, 'conversation']);
            Route::delete('/c/h/{conversationId}', [ChatController::class, 'deleteConversation']);
            Route::patch('/c/h/{conversationId}', [ChatWorkspaceController::class, 'updateConversation']);
            Route::get('/c/h/{conversationId}/attachments', [ChatAttachmentController::class, 'index']);
            Route::post('/c/h/{conversationId}/attachments', [ChatAttachmentController::class, 'store'])->middleware(['storage.available', 'throttle:30,1,chat-attachments']);
            Route::delete('/c/h/{conversationId}/attachments/{attachment}', [ChatAttachmentController::class, 'destroy'])->whereUuid('attachment');
            Route::get('/c/h/{conversationId}/attachments/{attachment}/preview', [ChatAttachmentController::class, 'preview'])->whereUuid('attachment');
            Route::get('/c/h/{conversationId}/attachments/{attachment}/download', [ChatAttachmentController::class, 'download'])->whereUuid('attachment');
            Route::get('/c/h/{key}/artifacts', [ChatArtifactController::class, 'index']);
            Route::post('/c/h/{key}/artifacts', [ChatArtifactController::class, 'store'])->middleware(['storage.available', 'throttle:30,1,chat-artifacts']);
            Route::get('/c/artifacts/{artifact}', [ChatArtifactController::class, 'show'])->whereUuid('artifact');
            Route::post('/c/artifacts/{artifact}/revisions', [ChatArtifactController::class, 'revise'])->whereUuid('artifact')->middleware(['storage.available', 'throttle:60,1,chat-artifact-revisions']);
            Route::get('/c/artifacts/{artifact}/revisions/{revision}', [ChatArtifactController::class, 'revision'])->whereUuid(['artifact', 'revision']);
            Route::get('/c/artifacts/{artifact}/download', [ChatArtifactController::class, 'download'])->whereUuid('artifact');
            Route::get('/c/artifacts/{artifact}/preview', [ChatArtifactController::class, 'preview'])->whereUuid('artifact');
        });
        // A running chat response stays observable and stoppable even if chat access is revoked mid-stream.
        Route::get('/c/operations/{operationId}', [ChatController::class, 'operation'])->whereUuid('operationId');
        Route::post('/c/operations/{operationId}/stop', [ChatController::class, 'stop'])->whereUuid('operationId');

        // Video and image generation
        Route::post('/v/{jobId}/cancel', [VideoController::class, 'cancel']);
        Route::get('/v/{jobId}/reference', [VideoController::class, 'reference']);
        Route::delete('/v/{jobId}/reference', [VideoController::class, 'destroyReference']);
        Route::middleware('feature.access')->group(function () {
            Route::get('/v/models', [VideoController::class, 'models']);
            Route::post('/v/gen', [VideoController::class, 'generate'])->middleware('storage.available');
            Route::get('/v/history', [VideoController::class, 'history']);
            Route::delete('/v/history', [VideoController::class, 'destroyAll']);
            Route::delete('/v/{jobId}', [VideoController::class, 'destroy']);
            Route::get('/v/status/{jobId}', [VideoController::class, 'status']);
            Route::get('/v/{jobId}/asset', [VideoController::class, 'asset']);

            Route::get('/images/models', [ImageController::class, 'models']);
            Route::post('/images', [ImageController::class, 'generate'])->middleware('storage.available');
            Route::get('/images', [ImageController::class, 'history']);
            Route::delete('/images', [ImageController::class, 'destroyAll']);
            Route::delete('/images/{jobId}', [ImageController::class, 'destroy']);
            Route::get('/images/{jobId}/assets/{index}', [ImageController::class, 'asset'])->whereNumber('index');
            Route::get('/images/{jobId}', [ImageController::class, 'show']);
        });
        // Controlled reference uploads (image/audio/video) for capability-driven studios.
        Route::post('/media/assets', [MediaAssetController::class, 'upload'])->middleware(['storage.available', 'throttle:30,1']);
        Route::get('/media/assets', [MediaAssetController::class, 'index']);
        Route::get('/media/assets/policy', [MediaAssetController::class, 'policy']);
        Route::get('/media/assets/{asset}', [MediaAssetController::class, 'show']);
        Route::delete('/media/assets/{asset}', [MediaAssetController::class, 'destroy']);

        // Unified capability workspace for every provider operation, plus realtime sessions.
        Route::middleware('feature.access')->group(function () {
            Route::get('/media/workspace/models', [WorkspaceMediaController::class, 'models']);
            Route::get('/media/workspace/capabilities', [WorkspaceMediaController::class, 'capabilities']);
            // Storage is checked by the service after replay: a lost response for paid work must stay recoverable.
            Route::post('/media/workspace/jobs', [WorkspaceMediaController::class, 'store'])->middleware('throttle:20,1,media-workspace-jobs');
            Route::post('/media/realtime/ice', [RealtimeMediaController::class, 'ice'])->middleware('throttle:20,1,realtime-ice');
            Route::post('/media/realtime/sessions', [RealtimeMediaController::class, 'store'])->middleware('throttle:10,1,realtime-session');
            Route::post('/media/realtime/sessions/{session}/input', [RealtimeMediaController::class, 'input'])->whereUuid('session')->middleware('throttle:60,1,realtime-input');
        });
        // Existing results, cancellation and live-session shutdown stay available without the feature permission.
        Route::get('/media/workspace/jobs', [WorkspaceMediaController::class, 'index']);
        Route::delete('/media/workspace/jobs', [WorkspaceMediaController::class, 'clear']);
        Route::get('/media/workspace/jobs/{id}', [WorkspaceMediaController::class, 'show'])->where('id', '[A-Za-z0-9:_-]{1,100}');
        Route::post('/media/workspace/jobs/{id}/cancel', [WorkspaceMediaController::class, 'cancel'])->where('id', '[A-Za-z0-9:_-]{1,100}');
        Route::post('/media/workspace/jobs/{id}/retry-save', [WorkspaceMediaController::class, 'retrySave'])->where('id', '[A-Za-z0-9:_-]{1,100}')->middleware('throttle:20,1,media-workspace-save');
        Route::delete('/media/workspace/jobs/{id}', [WorkspaceMediaController::class, 'destroy'])->where('id', '[A-Za-z0-9:_-]{1,100}');
        Route::get('/media/workspace/jobs/{id}/outputs/{outputId}/download', [WorkspaceMediaController::class, 'download'])->where('id', '[A-Za-z0-9:_-]{1,100}');
        Route::get('/media/workspace/jobs/{id}/outputs/{outputId}/preview', [WorkspaceMediaController::class, 'preview'])->where('id', '[A-Za-z0-9:_-]{1,100}');
        Route::get('/media/realtime/sessions', [RealtimeMediaController::class, 'index']);
        Route::get('/media/realtime/sessions/{session}', [RealtimeMediaController::class, 'show'])->whereUuid('session');
        Route::post('/media/realtime/sessions/{session}/heartbeat', [RealtimeMediaController::class, 'heartbeat'])->whereUuid('session')->middleware('throttle:30,1,realtime-heartbeat');
        Route::post('/media/realtime/sessions/{session}/close', [RealtimeMediaController::class, 'close'])->whereUuid('session');
        Route::post('/media/realtime/sessions/{session}/recording', [RealtimeMediaController::class, 'recording'])->whereUuid('session')->middleware(['storage.available', 'throttle:10,1,realtime-recording']);

        Route::get('/avatar/models', [AvatarController::class, 'models'])->middleware('feature.access');
        Route::post('/avatar', [AvatarController::class, 'generate'])->middleware(['feature.access', 'storage.available', 'throttle:10,1']);
        Route::get('/avatar', [AvatarController::class, 'history']);
        Route::get('/avatar/{jobId}', [VideoController::class, 'status']);
        Route::get('/avatar/{jobId}/asset', [VideoController::class, 'asset']);
        Route::post('/avatar/{jobId}/cancel', [VideoController::class, 'cancel']);
        Route::delete('/avatar/{jobId}', [VideoController::class, 'destroy']);

        Route::get('/3d/models', [ThreeDController::class, 'models'])->middleware('feature.access');
        Route::post('/3d', [ThreeDController::class, 'generate'])->middleware(['feature.access', 'throttle:10,1']);
        Route::get('/3d', [ThreeDController::class, 'history']);
        Route::get('/3d/{jobId}', [ThreeDController::class, 'show']);
        Route::get('/3d/{jobId}/asset', [ThreeDController::class, 'asset']);
        Route::post('/3d/{jobId}/cancel', [ThreeDController::class, 'cancel']);
        Route::delete('/3d/{jobId}', [ThreeDController::class, 'destroy']);

        // Existing outputs and cancellation stay recoverable without the generation permission.
        Route::get('/audio/models', [AudioController::class, 'models'])->middleware('feature.access');
        Route::post('/audio', [AudioController::class, 'generate'])->middleware(['feature.access', 'storage.available', 'throttle:10,1']);
        Route::get('/audio', [AudioController::class, 'history']);
        Route::get('/audio/{jobId}', [AudioController::class, 'show']);
        Route::post('/audio/{jobId}/cancel', [AudioController::class, 'cancel']);
        Route::get('/audio/{jobId}/assets/{index}', [AudioController::class, 'asset'])->whereNumber('index');
        Route::post('/audio/{jobId}/reference', [AudioController::class, 'reference'])->middleware(['storage.available', 'throttle:30,1']);
        Route::delete('/audio/{jobId}', [AudioController::class, 'destroy']);

        Route::get('/media-tools/capabilities', [MediaToolController::class, 'capabilities']);
        Route::get('/media-tools', [MediaToolController::class, 'history']);
        Route::delete('/media-tools', [MediaToolController::class, 'destroyAll']);
        Route::post('/media-tools/inspect', [MediaToolController::class, 'inspect'])->middleware(['feature.access', 'throttle:20,1']);
        Route::post('/media-tools/download', [MediaToolController::class, 'download'])->middleware(['feature.access', 'storage.available', 'throttle:10,1']);
        Route::post('/media-tools/convert', [MediaToolController::class, 'convert'])->middleware(['feature.access', 'storage.available', 'throttle:10,1']);
        Route::post('/media-tools/rembg', [MediaToolController::class, 'removeBackground'])->middleware(['feature.access', 'storage.available', 'throttle:10,1']);
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
        Route::post('/deposits/{depositOrder}/cancel', [DepositController::class, 'cancel'])->middleware('throttle:20,1');

        Route::get('/referrals/me', [ReferralController::class, 'memberStats']);
        // Admin
        Route::middleware(['admin', 'admin.ip'])->group(function () {
            // Admin: topup tokens
            Route::post('/t/topup', [TokenController::class, 'topup']);
            // Admin: security controls (IP allowlist, admin 2FA policy)
            Route::get('/security/overview', [SecurityController::class, 'overview']);
            Route::get('/security/settings', [SecurityController::class, 'index']);
            Route::put('/security/settings', [SecurityController::class, 'update']);
            Route::get('/pricing/settings', [PricingController::class, 'index']);
            Route::patch('/pricing/durations/bulk', [PricingController::class, 'bulkSaveDurations']);
            Route::patch('/pricing/rates/bulk', [PricingController::class, 'bulkUpdateUsageRates']);
            Route::delete('/pricing/rates/bulk', [PricingController::class, 'bulkDestroyUsageRates']);
            Route::put('/pricing/durations/{package}', [PricingController::class, 'saveDuration']);
            Route::post('/pricing/rates', [PricingController::class, 'saveUsageRate']);
            Route::put('/pricing/rates/{usageRate}', [PricingController::class, 'saveUsageRate']);
            Route::delete('/pricing/rates/{usageRate}', [PricingController::class, 'destroyUsageRate']);
            Route::get('/admin/pricing/auto', [AutoPricingController::class, 'index']);
            Route::put('/admin/pricing/auto/settings', [AutoPricingController::class, 'settings']);
            Route::put('/admin/pricing/auto/providers/{provider}', [AutoPricingController::class, 'providerCost']);
            Route::post('/admin/pricing/auto/refresh', [AutoPricingController::class, 'refresh']);
            Route::get('/admin/pricing/auto/preview', [AutoPricingController::class, 'preview']);
            Route::put('/admin/pricing/auto/models/{model}', [AutoPricingController::class, 'modelCost']);
            Route::delete('/admin/pricing/auto/models/{model}/cost', [AutoPricingController::class, 'resetCost']);
            Route::post('/admin/pricing/auto/apply', [AutoPricingController::class, 'apply']);
            Route::post('/pricing/storage-plans', [PricingController::class, 'saveStoragePlan']);
            Route::put('/pricing/storage-plans/{plan}', [PricingController::class, 'saveStoragePlan']);
            Route::delete('/pricing/storage-plans/{plan}', [PricingController::class, 'destroyStoragePlan']);
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
            // Storage upgrade orders (admin)
            Route::get('/a/storage/orders', [StorageUpgradeController::class, 'index']);
            Route::post('/a/storage/orders/{order}/approve', [StorageUpgradeController::class, 'approve']);
            Route::post('/a/storage/orders/{order}/reject', [StorageUpgradeController::class, 'reject']);

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
            Route::get('/admin/referrals/review', [ReferralController::class, 'adminReview']);
            Route::post('/admin/referrals/{referral}/review', [ReferralController::class, 'review']);
            Route::put('/admin/referrals/configuration', [ReferralController::class, 'updateConfiguration']);
            // Content and operational intelligence
            Route::get('/admin/content', [ContentController::class, 'index']);
            Route::post('/admin/content', [ContentController::class, 'store']);
            Route::put('/admin/content/{contentBlock}', [ContentController::class, 'update']);
            Route::post('/admin/content/{contentBlock}/publish', [ContentController::class, 'publish']);
            Route::post('/admin/content/{contentBlock}/unpublish', [ContentController::class, 'unpublish']);
            Route::delete('/admin/content/{contentBlock}', [ContentController::class, 'destroy']);
            Route::get('/admin/analytics/funnel', [AnalyticsController::class, 'funnel']);
            Route::get('/admin/audit', [AuditController::class, 'index']);

            // AI catalog and media operations
            Route::get('/admin/ai/catalog', [AiCatalogController::class, 'index']);
            Route::get('/admin/ai/catalog/summary', [AiCatalogController::class, 'summary']);
            Route::get('/admin/ai/providers/{provider}/models', [AiCatalogController::class, 'providerModels']);
            Route::get('/admin/ai/models/{model}/capabilities', [MediaCatalogController::class, 'capabilities']);
            Route::post('/admin/ai/providers/{provider}/discover', [MediaCatalogController::class, 'discover'])->middleware('throttle:12,1');
            Route::post('/admin/ai/providers/{provider}/catalog-bulk', [MediaCatalogController::class, 'bulk'])->middleware('throttle:12,1');
            Route::post('/admin/ai/capabilities/{revision}/review', [MediaCatalogController::class, 'review']);
            Route::post('/admin/ai/capabilities/{revision}/publish', [MediaCatalogController::class, 'publish']);
            Route::post('/admin/ai/capabilities/{revision}/disable', [MediaCatalogController::class, 'disable']);
            Route::post('/admin/ai/capabilities/{revision}/rollback', [MediaCatalogController::class, 'rollback']);
            Route::post('/admin/ai/providers', [AiProviderController::class, 'store']);
            Route::patch('/admin/ai/providers/{provider}', [AiProviderController::class, 'update']);
            Route::delete('/admin/ai/providers/{provider}', [AiProviderController::class, 'destroy']);
            Route::post('/admin/ai/providers/{provider}/check', [AiProviderController::class, 'check']);
            Route::get('/admin/ai/providers/{provider}/account', [AiProviderController::class, 'account'])->middleware('throttle:30,1');
            Route::post('/admin/ai/providers/{provider}/sync', [AiCatalogController::class, 'sync']);
            Route::post('/admin/ai/models', [AiCatalogController::class, 'storeModel']);
            Route::patch('/admin/ai/models/bulk', [AiCatalogController::class, 'bulkUpdateModels']);
            Route::delete('/admin/ai/models/bulk', [AiCatalogController::class, 'bulkDestroyModels']);
            Route::patch('/admin/ai/models/{model}', [AiCatalogController::class, 'updateModel']);
            Route::get('/admin/media/queue', [ImageController::class, 'adminQueue']);
            Route::get('/admin/media/youtube-cookies', [MediaToolController::class, 'youtubeCookiesStatus']);
            Route::post('/admin/media/youtube-cookies', [MediaToolController::class, 'saveYoutubeCookies']);
        });

        // Member: duration orders (authenticated, not admin-only)
        Route::get('/period/packages', [PeriodController::class, 'packages']);
        Route::post('/period/checkout', [PeriodController::class, 'checkout']);
        Route::post('/period/order', [PeriodController::class, 'store']);
        Route::post('/period/order/{order}/cancel', [PeriodController::class, 'cancel'])->middleware('throttle:20,1');
        Route::get('/period/my-orders', [PeriodController::class, 'myOrders']);

        // Member: storage upgrade orders
        Route::get('/storage/plans', [StorageUpgradeController::class, 'plans']);
        Route::get('/storage/usage', [StorageUpgradeController::class, 'usage']);
        Route::post('/storage/checkout', [StorageUpgradeController::class, 'checkout']);
        Route::post('/storage/order', [StorageUpgradeController::class, 'store']);
        Route::post('/storage/order/{order}/cancel', [StorageUpgradeController::class, 'cancel'])->middleware('throttle:20,1');
        Route::get('/storage/my-orders', [StorageUpgradeController::class, 'myOrders']);

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
