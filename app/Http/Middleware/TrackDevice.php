<?php

namespace App\Http\Middleware;

use App\Models\UserDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        // Track device for ALL users (including admin)
        // Admin: unlimited devices, just track
        // Member: max 2 devices, block if over
        $maxDevices = $user->isAdmin() ? 999 : 2;
        $device = UserDevice::trackDevice($user->id, $request, $maxDevices);

        if ($device === null && !$user->isAdmin()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Batas perangkat tercapai (maksimal 2). Hubungi admin untuk menambah perangkat.',
                    'device_limit' => true,
                ], 403);
            }
        }

        return $next($request);
    }
}
