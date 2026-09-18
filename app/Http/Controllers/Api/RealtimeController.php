<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RealtimeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user(), 401);

        $config = config('broadcasting.connections.reverb', []);
        $key = (string) ($config['key'] ?? '');
        $host = (string) data_get($config, 'options.host', '');
        $scheme = (string) data_get($config, 'options.scheme', 'https');
        $port = (int) data_get($config, 'options.port', $scheme === 'https' ? 443 : 80);
        $validHost = $host !== '' && strlen($host) <= 253
            && preg_match('/\A(?:[a-zA-Z0-9.-]+|\[[a-fA-F0-9:]+\])\z/', $host) === 1;
        $enabled = config('broadcasting.default') === 'reverb'
            && $key !== '' && $validHost && in_array($scheme, ['http', 'https'], true)
            && $port >= 1 && $port <= 65535;

        return response()->json([
            'enabled' => $enabled,
            'key' => $enabled ? $key : null,
            'host' => $enabled ? $host : null,
            'port' => $enabled ? $port : null,
            'scheme' => $enabled ? $scheme : null,
            'auth_endpoint' => '/api/broadcasting/auth',
        ])->header('Cache-Control', 'private, no-store');
    }
}
