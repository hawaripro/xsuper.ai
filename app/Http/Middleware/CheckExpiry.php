<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->isAdmin()) {
            // Check expired
            if ($user->isExpired()) {
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'message' => 'Akun Anda telah expired. Hubungi administrator untuk perpanjangan.',
                        'expired' => true,
                    ], 403);
                }
            }

            // Check chat permission
            if (!$user->hasPermission('chat')) {
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'message' => 'Anda tidak memiliki akses ke fitur ini.',
                        'forbidden' => true,
                    ], 403);
                }
            }
        }

        return $next($request);
    }
}
