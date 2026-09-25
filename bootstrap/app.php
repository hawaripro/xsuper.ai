<?php

use App\Exceptions\InsufficientBalanceException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'admin.ip' => \App\Http\Middleware\AdminIpAllowlist::class,
            'feature.access' => \App\Http\Middleware\EnsureFeatureAccess::class,
            'verify.apikey' => \App\Http\Middleware\VerifyApiKey::class,
            'track.device' => \App\Http\Middleware\TrackDevice::class,
            'ensure.active' => \App\Http\Middleware\EnsureActive::class,
            'storage.available' => \App\Http\Middleware\EnsureStorageAvailable::class,
        ]);

        // Make auth middleware return JSON 401 for AJAX/API requests
        // instead of redirecting to login page
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                abort(401, 'Unauthenticated.');
            }
            return '/login';
        });

        // Security headers for all responses
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Throttle abuse-prone auth endpoints (register/forgot/reset) and remember referral links.
        $middleware->web(append: [
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \App\Http\Middleware\ThrottleAuthEndpoints::class,
            \App\Http\Middleware\CaptureReferral::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (\Throwable $exception, Request $request) => \App\Http\Api\DeveloperApiErrors::render($exception, $request));
        // Running out of balance is an expected member outcome, not an application fault.
        $exceptions->dontReport(InsufficientBalanceException::class);
        // Return JSON for API errors
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (InsufficientBalanceException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json($e->payload(), 402);
            }
        });

        // Handle token mismatch (expired CSRF) — redirect to login
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Session expired'], 419);
            }
            return redirect('/login');
        });
    })->create();
