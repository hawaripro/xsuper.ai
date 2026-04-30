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
        if (!$user || $user->isAdmin()) {
            return $next($request);
        }

        $device = UserDevice::trackDevice($user->id, $request, 2);

        if ($device === null) {
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
