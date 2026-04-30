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

    /**
     * Generate device fingerprint from multiple signals
     * Even incognito mode can't hide all of these
     */
    public static function generateFingerprint(int $userId, Request $request): string
    {
        $signals = [
            $userId,
            $request->userAgent() ?? 'Unknown',
            $request->header('Accept-Language', ''),
            $request->header('Accept-Encoding', ''),
            $request->header('Sec-Ch-Ua', ''),
            $request->header('Sec-Ch-Ua-Platform', ''),
            $request->header('Sec-Ch-Ua-Mobile', ''),
        ];

        // For API requests (plugin), also use API key as signal
        $apiKey = $request->bearerToken();
        if ($apiKey) {
            $signals[] = substr($apiKey, 0, 20);
        }

        return hash('sha256', implode('|', $signals));
    }

    /**
     * Track device — returns null if blocked or over limit
     */
    public static function trackDevice(int $userId, Request $request, int $maxDevices = 2): ?self
    {
        $hash = self::generateFingerprint($userId, $request);
        $ua = $request->userAgent() ?? 'Unknown';
        $ip = $request->ip();

        $device = static::where('device_hash', $hash)->first();

        if ($device) {
            if ($device->status === 'blocked') {
                return null;
            }
            $device->update([
                'ip_address' => $ip,
                'last_active_at' => now(),
            ]);
            return $device;
        }

        // New device — check limit (admin exempt)
        $user = \App\Models\User::find($userId);
        if ($user && $user->isAdmin()) {
            return static::create([
                'user_id' => $userId,
                'device_hash' => $hash,
                'device_name' => self::parseDeviceName($ua),
                'device_type' => self::parseDeviceType($ua, $request),
                'user_agent' => substr($ua, 0, 500),
                'ip_address' => $ip,
                'status' => 'active',
                'last_active_at' => now(),
            ]);
        }

        $activeCount = static::where('user_id', $userId)->where('status', 'active')->count();
        if ($activeCount >= $maxDevices) {
            // Over limit — create as pending
            static::firstOrCreate(
                ['device_hash' => $hash],
                [
                    'user_id' => $userId,
                    'device_name' => self::parseDeviceName($ua),
                    'device_type' => self::parseDeviceType($ua, $request),
                    'user_agent' => substr($ua, 0, 500),
                    'ip_address' => $ip,
                    'status' => 'pending',
                    'last_active_at' => now(),
                ]
            );
            return null;
        }

        return static::create([
            'user_id' => $userId,
            'device_hash' => $hash,
            'device_name' => self::parseDeviceName($ua),
            'device_type' => self::parseDeviceType($ua, $request),
            'user_agent' => substr($ua, 0, 500),
            'ip_address' => $ip,
            'status' => 'active',
            'last_active_at' => now(),
        ]);
    }

    public static function parseDeviceName(string $ua): string
    {
        if (str_contains($ua, 'OpenCode') || str_contains($ua, 'opencode')) return 'OpenCode CLI';
        if (str_contains($ua, 'Continue')) return 'Continue Extension';
        if (str_contains($ua, 'Cursor')) return 'Cursor Editor';
        if (str_contains($ua, 'VSCode') || str_contains($ua, 'vscode')) return 'VS Code';
        if (str_contains($ua, 'Copilot')) return 'GitHub Copilot';
        if (str_contains($ua, 'curl')) return 'cURL CLI';
        if (str_contains($ua, 'python') || str_contains($ua, 'Python')) return 'Python Script';
        if (str_contains($ua, 'node') || str_contains($ua, 'Node')) return 'Node.js';

        if (str_contains($ua, 'Edg/')) return 'Microsoft Edge';
        if (str_contains($ua, 'Chrome/') && !str_contains($ua, 'Edg/')) return 'Google Chrome';
        if (str_contains($ua, 'Firefox/')) return 'Mozilla Firefox';
        if (str_contains($ua, 'Safari/') && !str_contains($ua, 'Chrome/')) return 'Safari';
        if (str_contains($ua, 'Opera') || str_contains($ua, 'OPR/')) return 'Opera';

        // Mobile
        if (str_contains($ua, 'Mobile')) return 'Mobile Browser';
        if (str_contains($ua, 'Android')) return 'Android Browser';
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) return 'iOS Browser';

        return 'Unknown Device';
    }

    public static function parseDeviceType(string $ua, ?Request $request = null): string
    {
        // API key = plugin
        if ($request && $request->bearerToken() && str_starts_with($request->bearerToken(), 'ultrai-')) return 'plugin';

        if (str_contains($ua, 'OpenCode') || str_contains($ua, 'opencode') || str_contains($ua, 'Continue') || str_contains($ua, 'Cursor') || str_contains($ua, 'VSCode') || str_contains($ua, 'vscode') || str_contains($ua, 'Copilot')) return 'plugin';
        if (str_contains($ua, 'curl') || str_contains($ua, 'python') || str_contains($ua, 'Python') || str_contains($ua, 'node') || str_contains($ua, 'Node')) return 'cli';
        if (str_contains($ua, 'Mobile') || str_contains($ua, 'Android') || str_contains($ua, 'iPhone')) return 'mobile';
        return 'browser';
    }
}
