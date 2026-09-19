<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks deactivated accounts (is_active = false) from every authenticated
 * surface. Admins are exempt so a mistaken toggle cannot lock the control room.
 */
class EnsureActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Only an explicit deactivation (is_active === false) locks the account; a
        // null/unknown flag is treated as active so we never fail closed by accident.
        if ($user && $user->is_active === false && $user->role !== 'admin') {
            return response()->json([
                'message' => 'Akun Anda dinonaktifkan. Hubungi admin.',
                'code' => 'account_disabled',
            ], 403);
        }

        return $next($request);
    }
}
