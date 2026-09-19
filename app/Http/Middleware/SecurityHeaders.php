<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking — only allow same origin iframes
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Content-Security-Policy — defence-in-depth against injected content.
        // Local/debug relaxes script/connect rules so the Vite dev server (HMR,
        // eval, ws) keeps working; production ships the strict policy.
        $umami = 'https://cloud.umami.is';
        if (config('app.debug')) {
            $script = "'self' 'unsafe-inline' 'unsafe-eval' ".$umami;
            $connect = "'self' ws: wss: http: https:";
        } else {
            $script = "'self' ".$umami;
            $connect = "'self' https: wss:";
        }
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "media-src 'self' blob:",
            "connect-src {$connect}",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]));

        // Control referrer information
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Prevent browsers from caching sensitive pages
        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
        }

        // Permissions Policy — disable unnecessary browser features
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // Strict Transport Security — force HTTPS (1 year)
        if (config('app.env') === 'production') {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
