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

            // Check route-specific permissions
            $path = $request->path();

            // Chat routes: require 'chat' permission
            if (str_starts_with($path, 'api/c/')) {
                // allModels endpoint is allowed (it filters internally)
                if (!str_contains($path, '/c/am') && !$user->hasPermission('chat')) {
                    if ($request->expectsJson() || $request->is('api/*')) {
                        return response()->json([
                            'message' => 'Anda tidak memiliki akses ke fitur Chat.',
                            'forbidden' => true,
                        ], 403);
                    }
                }
            }

            // Video routes: require 'video_generator' permission
            if (str_starts_with($path, 'api/v/')) {
                if (!$user->hasPermission('video_generator')) {
                    if ($request->expectsJson() || $request->is('api/*')) {
                        return response()->json([
                            'message' => 'Anda tidak memiliki akses ke fitur Video Generator.',
                            'forbidden' => true,
                        ], 403);
                    }
                }
            }

            $permission = match ($path) {
                'api/audio', 'api/audio/models' => 'audio_generator',
                'api/media-tools/download' => 'video_downloader',
                'api/media-tools/convert' => 'media_converter',
                default => null,
            };
            if ($permission !== null && (! $user->is_active || ! $user->hasPermission($permission))) {
                return response()->json([
                    'message' => 'Anda tidak memiliki akses ke fitur ini.',
                    'forbidden' => true,
                ], 403);
            }
        }

        return $next($request);
    }
}
