<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class UserDevice extends Model
{
    protected $fillable = ['user_id', 'device_hash', 'device_name', 'device_type', 'user_agent', 'ip_address', 'status', 'last_active_at'];

    protected function casts(): array
    {
        return ['last_active_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function generateFingerprint(int $userId, Request $request): string
    {
        $signals = [
            $userId,
            $request->userAgent() ?? 'Unknown',
            $request->header('Accept-Language', ''),
            $request->header('Sec-Ch-Ua', ''),
            $request->header('Sec-Ch-Ua-Platform', ''),
        ];

        // API key as unique signal for plugins
        $apiKey = $request->bearerToken();
        if ($apiKey && str_starts_with($apiKey, 'ultrai-')) {
            $signals[] = 'apikey:' . substr($apiKey, 0, 20);
        }

        return hash('sha256', implode('|', $signals));
    }

    public static function trackDevice(int $userId, Request $request, int $maxDevices = 2): ?self
    {
        $hash = self::generateFingerprint($userId, $request);
        $ua = $request->userAgent() ?? 'Unknown';
        $ip = $request->ip();
        $isApiKey = $request->bearerToken() && str_starts_with($request->bearerToken() ?? '', 'ultrai-');

        // Try find existing device
        $device = static::where('device_hash', $hash)->first();

        if ($device) {
            if ($device->status === 'blocked') {
                return null;
            }
            // Update last active + IP
            $device->ip_address = $ip;
            $device->last_active_at = now();
            $device->save();
            return $device;
        }

        // New device
        $deviceName = $isApiKey ? self::parsePluginName($ua, $request) : self::parseDeviceName($ua);
        $deviceType = $isApiKey ? 'plugin' : self::parseDeviceType($ua);

        // Check limit for non-admin
        $user = User::find($userId);
        $isAdmin = $user && $user->isAdmin();

        if (!$isAdmin) {
            $activeCount = static::where('user_id', $userId)->where('status', 'active')->count();
            if ($activeCount >= $maxDevices) {
                // Over limit — create as pending (needs admin approval)
                return static::updateOrCreate(
                    ['device_hash' => $hash],
                    [
                        'user_id' => $userId,
                        'device_name' => $deviceName,
                        'device_type' => $deviceType,
                        'user_agent' => substr($ua, 0, 500),
                        'ip_address' => $ip,
                        'status' => 'pending',
                        'last_active_at' => now(),
                    ]
                );
            }
        }

        // Create active device
        return static::updateOrCreate(
            ['device_hash' => $hash],
            [
                'user_id' => $userId,
                'device_name' => $deviceName,
                'device_type' => $deviceType,
                'user_agent' => substr($ua, 0, 500),
                'ip_address' => $ip,
                'status' => 'active',
                'last_active_at' => now(),
            ]
        );
    }

    /**
     * Parse plugin name from API request
     */
    public static function parsePluginName(string $ua, Request $request): string
    {
        // Check common plugin user-agents
        if (str_contains($ua, 'opencode') || str_contains($ua, 'OpenCode')) return 'OpenCode CLI';
        if (str_contains($ua, 'Continue')) return 'Continue Extension';
        if (str_contains($ua, 'Cursor')) return 'Cursor Editor';
        if (str_contains($ua, 'Copilot')) return 'GitHub Copilot';
        if (str_contains($ua, 'vscode') || str_contains($ua, 'VSCode')) return 'VS Code Extension';
        if (str_contains($ua, 'curl')) return 'cURL CLI';
        if (str_contains($ua, 'python') || str_contains($ua, 'Python')) return 'Python Script';
        if (str_contains($ua, 'node') || str_contains($ua, 'Node') || str_contains($ua, 'axios')) return 'Node.js App';
        if (str_contains($ua, 'Go-http-client')) return 'Go App';
        if (str_contains($ua, 'Java')) return 'Java App';

        // If has API key but unknown UA, label as API Client
        if ($request->bearerToken()) return 'API Client';

        return 'Unknown Plugin';
    }

    public static function parseDeviceName(string $ua): string
    {
        if (str_contains($ua, 'Edg/')) return 'Microsoft Edge';
        if (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')) return 'Opera';
        if (str_contains($ua, 'Chrome/') && !str_contains($ua, 'Edg/')) {
            if (str_contains($ua, 'Mobile')) return 'Chrome Mobile';
            return 'Google Chrome';
        }
        if (str_contains($ua, 'Firefox/')) {
            if (str_contains($ua, 'Mobile')) return 'Firefox Mobile';
            return 'Mozilla Firefox';
        }
        if (str_contains($ua, 'Safari/') && !str_contains($ua, 'Chrome/')) {
            if (str_contains($ua, 'Mobile') || str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) return 'Safari Mobile';
            return 'Safari';
        }
        if (str_contains($ua, 'CriOS')) return 'Chrome iOS';
        if (str_contains($ua, 'FxiOS')) return 'Firefox iOS';
        if (str_contains($ua, 'Mobile')) return 'Mobile Browser';

        return 'Unknown Browser';
    }

    public static function parseDeviceType(string $ua): string
    {
        if (str_contains($ua, 'Mobile') || str_contains($ua, 'Android') || str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) return 'mobile';
        return 'browser';
    }
}
