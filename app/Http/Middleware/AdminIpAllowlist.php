<?php

namespace App\Http\Middleware;

use App\Models\SecuritySetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces admin-side security policy configured in SecuritySetting:
 *  - IP allowlist (CIDR-aware) for admin routes when enabled;
 *  - optional mandatory 2FA for admins (they may still reach /profile to enrol).
 */
class AdminIpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = SecuritySetting::current();

        if (! $settings->ipAllowed($request->ip())) {
            return response()->json([
                'message' => 'Alamat IP Anda tidak diizinkan untuk akses admin.',
                'code' => 'ip_not_allowed',
            ], 403);
        }

        $user = $request->user();
        if ($settings->require_admin_2fa
            && $user
            && $user->role === 'admin'
            && ! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json([
                'message' => 'Admin wajib mengaktifkan 2FA. Aktifkan di halaman Profil dulu.',
                'code' => 'admin_2fa_required',
            ], 403);
        }

        return $next($request);
    }
}
