<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\UserDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer || ! str_starts_with($bearer, 'xsuper-')) {
            return response()->json([
                'error' => ['message' => 'Invalid API key', 'type' => 'authentication_error'],
            ], 401);
        }

        // Keys are stored hashed; look up by sha256 so a DB leak yields no secrets.
        $apiKey = ApiKey::findByPlainKey($bearer);

        if (! $apiKey || ! $apiKey->isValid()) {
            return response()->json([
                'error' => ['message' => 'Invalid or expired API key', 'type' => 'authentication_error'],
            ], 401);
        }

        $user = $apiKey->user;
        if (! $user || $user->isExpired() || $user->is_active === false) {
            return response()->json([
                'error' => ['message' => 'Account expired or disabled', 'type' => 'authentication_error'],
            ], 403);
        }

        // The API is a gated capability; the permission is re-checked on every call
        // so revoking it takes effect immediately even for existing keys.
        if (! $user->isAdmin() && ! $user->hasPermission('ai_api')) {
            return response()->json([
                'error' => ['message' => 'API access is not enabled for this account', 'type' => 'permission_error'],
            ], 403);
        }

        // Trusted identity travels via request attributes — never through input. Device
        // tracking reads the verified key from here to select the plugin identity.
        $request->attributes->set('api_user', $user);
        $request->attributes->set('api_key', $apiKey);

        // Device policy mirrors the session middleware: block blocked/pending devices.
        $maxDevices = $user->isAdmin() ? 999 : 2;
        $device = UserDevice::trackDevice($user->id, $request, $maxDevices);
        if (! $user->isAdmin() && ($device === null || $device->status === 'pending')) {
            return response()->json([
                'error' => ['message' => 'Device limit reached (max 2) or awaiting approval. Contact admin.', 'type' => 'device_limit_error'],
            ], 403);
        }

        // Atomic fixed-window limiter (no read-modify-write race) keyed per key.
        $limiterKey = 'api_rate:'.$apiKey->id;
        if (RateLimiter::tooManyAttempts($limiterKey, $apiKey->rate_limit)) {
            return response()->json([
                'error' => ['message' => 'Rate limit exceeded. Max '.$apiKey->rate_limit.' requests/min', 'type' => 'rate_limit_error'],
            ], 429);
        }
        RateLimiter::hit($limiterKey, 60);

        $apiKey->recordUsage();

        return $next($request);
    }
}
