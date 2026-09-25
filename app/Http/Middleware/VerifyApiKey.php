<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiErrorResponse;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken() ?: $request->header('x-api-key');
        $apiKey = is_string($plain) && str_starts_with($plain, 'xsuper-') ? ApiKey::findByPlainKey($plain) : null;
        if (! $apiKey || ! $apiKey->isValid()) {
            return $this->error($request, 'Invalid or expired API key', 'authentication_error', 401);
        }
        $user = $apiKey->user;
        if (! $user || $user->is_active === false) {
            return $this->error($request, 'Account disabled', 'permission_error', 403);
        }
        if (! $user->hasVerifiedEmail()) {
            return $this->error($request, 'Verify your email before using the API', 'permission_error', 403);
        }
        if (! $user->isAdmin() && ! $user->hasPermission('ai_api')) {
            return $this->error($request, 'API access is not enabled for this account', 'permission_error', 403);
        }

        // Coding tools are authenticated by keys, not browser devices or membership periods.
        $request->attributes->set('api_user', $user);
        $request->attributes->set('api_key', $apiKey);
        $limiterKey = 'api_rate:'.$apiKey->id;
        if (RateLimiter::tooManyAttempts($limiterKey, $apiKey->rate_limit)) {
            return $this->error($request, 'Rate limit exceeded. Max '.$apiKey->rate_limit.' requests/min', 'rate_limit_error', 429);
        }
        RateLimiter::hit($limiterKey, 60);
        $apiKey->recordUsage();

        return $next($request);
    }

    private function error(Request $request, string $message, string $type, int $status): Response
    {
        return $request->is('v1/messages*')
            ? ApiErrorResponse::anthropic($type, $message, $status)
            : ApiErrorResponse::openAi($message, $type, $type, $status);
    }
}
