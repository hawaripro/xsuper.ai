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

        $maxDevices = $user->isAdmin() ? 999 : 2;
        $device = UserDevice::trackDevice($user->id, $request, $maxDevices);

        // If device is pending (over limit) and user is not admin
        if (!$user->isAdmin() && $device && $device->status === 'pending') {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Perangkat baru terdeteksi. Menunggu persetujuan admin (maksimal 2 perangkat aktif).',
                    'device_pending' => true,
                    'device_name' => $device->device_name,
                ], 403);
            }
        }

        // If device is null (blocked)
        if ($device === null && !$user->isAdmin()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Perangkat ini diblokir. Hubungi admin.',
                    'device_blocked' => true,
                ], 403);
            }
        }

        return $next($request);
    }
}
