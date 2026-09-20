<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts that have not activated their email may only manage their own
 * profile (name/password), request/verify activation codes, and sign out.
 * Every other authenticated API answers 403 with `email_unverified` so the
 * SPA can route the member to the activation screen.
 */
class EnsureEmailVerified
{
    /**
     * Paths an unverified member must still reach. The notification bell,
     * realtime handshake and broadcast auth are part of the application shell
     * that renders on the activation screen itself — blocking them returned a
     * 403 the SPA read as a dead session, so the inbox stayed broken.
     */
    private const ALLOWED = [
        'api/u/me',
        'api/u/p',
        'api/u/pw',
        'api/u/verify-email',
        'api/u/verify-email/send',
        'api/user',
        'api/logout',
        'api/health',
        'api/notifications',
        'api/notifications/read-all',
        'api/realtime/config',
        'api/broadcasting/auth',
    ];

    private const ALLOWED_PREFIXES = [
        'api/notifications/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isAdmin() && $user->email_verified_at === null) {
            $path = strtolower(trim($request->path(), '/'));
            $allowed = in_array($path, self::ALLOWED, true);
            foreach (self::ALLOWED_PREFIXES as $prefix) {
                $allowed = $allowed || str_starts_with($path, $prefix);
            }
            if (! $allowed) {
                return response()->json([
                    'message' => 'Aktifkan email Anda terlebih dahulu untuk mengakses fitur ini.',
                    'email_unverified' => true,
                ], 403);
            }
        }

        return $next($request);
    }
}
