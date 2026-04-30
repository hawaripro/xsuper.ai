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
     * Register or update device from request
     * Returns null if device limit reached and device is new
     */
    public static function trackDevice(int $userId, Request $request, int $maxDevices = 2): ?self
    {
        $ua = $request->userAgent() ?? 'Unknown';
        $ip = $request->ip();
        $hash = hash('sha256', $userId . '|' . $ua);

        $device = static::where('device_hash', $hash)->first();

        if ($device) {
            // Existing device — update last active
            $device->update([
                'ip_address' => $ip,
                'last_active_at' => now(),
            ]);

            if ($device->status === 'blocked') {
                return null; // Blocked device
            }

            return $device;
        }

        // New device — check limit
        $activeCount = static::where('user_id', $userId)->where('status', 'active')->count();
        if ($activeCount >= $maxDevices) {
            // Create as pending (needs admin approval or auto-reject)
            $device = static::create([
                'user_id' => $userId,
                'device_hash' => $hash,
                'device_name' => self::parseDeviceName($ua),
                'device_type' => self::parseDeviceType($ua),
                'user_agent' => substr($ua, 0, 500),
                'ip_address' => $ip,
                'status' => 'pending',
                'last_active_at' => now(),
            ]);
            return null; // Over limit
        }

        // Create new active device
        return static::create([
            'user_id' => $userId,
            'device_hash' => $hash,
            'device_name' => self::parseDeviceName($ua),
            'device_type' => self::parseDeviceType($ua),
            'user_agent' => substr($ua, 0, 500),
            'ip_address' => $ip,
            'status' => 'active',
            'last_active_at' => now(),
        ]);
    }

    public static function parseDeviceName(string $ua): string
    {
        if (str_contains($ua, 'OpenCode')) return 'OpenCode CLI';
        if (str_contains($ua, 'Continue')) return 'Continue Extension';
        if (str_contains($ua, 'Cursor')) return 'Cursor Editor';
        if (str_contains($ua, 'VSCode') || str_contains($ua, 'vscode')) return 'VS Code';
        if (str_contains($ua, 'Copilot')) return 'GitHub Copilot';
        if (str_contains($ua, 'curl')) return 'cURL CLI';
        if (str_contains($ua, 'python') || str_contains($ua, 'Python')) return 'Python Script';
        if (str_contains($ua, 'node') || str_contains($ua, 'Node')) return 'Node.js';

        // Browser detection
        if (str_contains($ua, 'Edg/')) return 'Microsoft Edge';
        if (str_contains($ua, 'Chrome/') && !str_contains($ua, 'Edg/')) return 'Google Chrome';
        if (str_contains($ua, 'Firefox/')) return 'Mozilla Firefox';
        if (str_contains($ua, 'Safari/') && !str_contains($ua, 'Chrome/')) return 'Safari';
        if (str_contains($ua, 'Opera') || str_contains($ua, 'OPR/')) return 'Opera';

        return 'Unknown Device';
    }

    public static function parseDeviceType(string $ua): string
    {
        if (str_contains($ua, 'OpenCode') || str_contains($ua, 'Continue') || str_contains($ua, 'Cursor') || str_contains($ua, 'VSCode') || str_contains($ua, 'vscode') || str_contains($ua, 'Copilot')) return 'plugin';
        if (str_contains($ua, 'curl') || str_contains($ua, 'python') || str_contains($ua, 'Python') || str_contains($ua, 'node') || str_contains($ua, 'Node')) return 'cli';
        return 'browser';
    }
}
