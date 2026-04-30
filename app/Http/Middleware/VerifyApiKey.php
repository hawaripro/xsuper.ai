<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\UserDevice;
use App\Models\UsageLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (!$bearer || !str_starts_with($bearer, 'ultrai-')) {
            return response()->json([
                'error' => ['message' => 'Invalid API key', 'type' => 'authentication_error']
            ], 401);
        }

        $apiKey = ApiKey::where('key', $bearer)->first();

        if (!$apiKey || !$apiKey->isValid()) {
            return response()->json([
                'error' => ['message' => 'Invalid or expired API key', 'type' => 'authentication_error']
            ], 401);
        }

        // Check user expiry
        $user = $apiKey->user;
        if ($user->isExpired()) {
            return response()->json([
                'error' => ['message' => 'Account expired', 'type' => 'authentication_error']
            ], 403);
        }

        // Rate limiting (per minute)
        $rateLimitKey = 'api_rate:' . $apiKey->id;
        $requests = Cache::get($rateLimitKey, 0);
        if ($requests >= $apiKey->rate_limit) {
            return response()->json([
                'error' => ['message' => 'Rate limit exceeded. Max ' . $apiKey->rate_limit . ' requests/min', 'type' => 'rate_limit_error']
            ], 429);
        }
        Cache::put($rateLimitKey, $requests + 1, 60);

        // Record usage
        $apiKey->recordUsage();

        // Track device for ALL users
        $maxDevices = $user->isAdmin() ? 999 : 2;
        $device = UserDevice::trackDevice($user->id, $request, $maxDevices);
        if ($device === null && !$user->isAdmin()) {
            return response()->json([
                'error' => ['message' => 'Device limit reached (max 2). Contact admin.', 'type' => 'device_limit_error']
            ], 403);
        }

        // Store user & apiKey for downstream use
        $request->merge(['_api_user' => $user, '_api_key' => $apiKey]);

        return $next($request);
    }
}
