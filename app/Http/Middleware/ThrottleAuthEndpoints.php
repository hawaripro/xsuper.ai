<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-IP rate limiting for unauthenticated, abuse-prone auth POST endpoints that
 * Fortify registers without a throttle (register, forgot-password, reset-password).
 */
class ThrottleAuthEndpoints
{
    private const LIMITS = [
        'register' => 5,
        'forgot-password' => 5,
        'reset-password' => 10,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST')) {
            $path = ltrim($request->path(), '/');
            $limit = self::LIMITS[$path] ?? null;
            if ($limit !== null) {
                $key = 'auth-endpoint:'.$path.'|'.$request->ip();
                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    $seconds = RateLimiter::availableIn($key);

                    return response()->json([
                        'message' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
                    ], 429);
                }
                RateLimiter::hit($key, 60);
            }
        }

        return $next($request);
    }
}
