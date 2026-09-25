<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserDevice extends Model
{
    private const SESSION_DEVICE_KEY = 'auth.user_device_id';

    /** A known device's activity is refreshed at most this often; a new address is recorded at once. */
    private const ACTIVITY_INTERVAL_SECONDS = 60;

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

        // A verified API key is the unique plugin signal; the raw-key prefix keeps existing fingerprints stable.
        if (self::viaVerifiedApiKey($request)) {
            $signals[] = 'apikey:' . substr($request->bearerToken(), 0, 20);
        }

        return hash('sha256', implode('|', $signals));
    }

    /** Resolve an existing identity without recording activity or allocating a device slot. */
    public static function findForRequest(int $userId, Request $request): ?self
    {
        if (! self::viaVerifiedApiKey($request) && $request->hasSession()
            && $request->user()?->getAuthIdentifier() === $userId) {
            $deviceId = $request->session()->get(self::SESSION_DEVICE_KEY);
            if (is_int($deviceId) && ($device = static::where('user_id', $userId)->find($deviceId))) {
                return $device;
            }
        }

        return static::where('user_id', $userId)
            ->where('device_hash', self::generateFingerprint($userId, $request))
            ->first();
    }

    public static function trackDevice(int $userId, Request $request, int $maxDevices = 2): ?self
    {
        $ip = $request->ip();
        $device = self::findForRequest($userId, $request);

        if ($device === null) {
            // Only admitting a NEW identity allocates a device slot, so only admission is serialized on the
            // owner's row (concurrent new fingerprints still cannot both take the last slot); requests from
            // known devices never queue behind the member's other requests.
            $device = DB::transaction(function () use ($userId, $request, $maxDevices, $ip): self {
                $user = User::query()->lockForUpdate()->findOrFail($userId);
                // A concurrent request may have admitted this fingerprint while this one waited for the lock.
                if ($admitted = self::findForRequest($userId, $request)) {
                    return $admitted;
                }
                $ua = $request->userAgent() ?? 'Unknown';
                $isApiKey = self::viaVerifiedApiKey($request);
                $status = ! $user->isAdmin()
                    && static::where('user_id', $userId)->where('status', 'active')->count() >= $maxDevices
                    ? 'pending' : 'active';

                return static::create([
                    'device_hash' => self::generateFingerprint($userId, $request),
                    'user_id' => $userId,
                    'device_name' => $isApiKey ? self::parsePluginName($ua, $request) : self::parseDeviceName($ua),
                    'device_type' => $isApiKey ? 'plugin' : self::parseDeviceType($ua),
                    'user_agent' => substr($ua, 0, 500),
                    'ip_address' => $ip,
                    'status' => $status,
                    'last_active_at' => now(),
                ]);
            });
        } elseif ($device->status !== 'blocked') {
            self::recordActivity($device, $ip);
        }

        // Bind denied identities too; only a validated API key selects stateless plugin identity.
        if (! self::viaVerifiedApiKey($request) && $request->hasSession() && $request->user()?->getAuthIdentifier() === $userId) {
            $request->session()->put(self::SESSION_DEVICE_KEY, $device->getKey());
        }

        return $device->status === 'blocked' ? null : $device;
    }

    private static function recordActivity(self $device, ?string $ip): void
    {
        $now = now();
        if ($device->ip_address === $ip && $device->last_active_at !== null
            && $device->last_active_at->gt($now->copy()->subSeconds(self::ACTIVITY_INTERVAL_SECONDS))) {
            return;
        }
        // One conditional statement: it never waits on the owner's row and never touches a device an
        // administrator blocked after it was read.
        static::query()->whereKey($device->getKey())->where('status', '!=', 'blocked')
            ->update(['ip_address' => $ip, 'last_active_at' => $now]);
        $device->forceFill(['ip_address' => $ip, 'last_active_at' => $now])->syncOriginalAttributes(['ip_address', 'last_active_at']);
    }

    /** Only VerifyApiKey's validated key selects plugin identity; a raw Bearer header is client input. */
    private static function viaVerifiedApiKey(Request $request): bool
    {
        return $request->attributes->get('api_key') instanceof ApiKey;
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
