<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\HttpFoundation\IpUtils;

class SecuritySetting extends Model
{
    public const SINGLETON_ID = 1;

    protected $fillable = ['id', 'enforce_admin_ip', 'admin_ip_allowlist', 'require_admin_2fa', 'updated_by'];

    protected function casts(): array
    {
        return [
            'enforce_admin_ip' => 'boolean',
            'admin_ip_allowlist' => 'array',
            'require_admin_2fa' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Cached singleton row; created on first read if the seed is absent. */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => self::SINGLETON_ID], [
            'enforce_admin_ip' => false,
            'admin_ip_allowlist' => [],
            'require_admin_2fa' => false,
        ]);
    }

    /** True when the IP is permitted given the current allowlist policy. */
    public function ipAllowed(?string $ip): bool
    {
        if (! $this->enforce_admin_ip) {
            return true;
        }
        $allow = array_values(array_filter((array) $this->admin_ip_allowlist));
        if ($allow === []) {
            // An enforced-but-empty allowlist would lock everyone out; treat as open.
            return true;
        }

        return is_string($ip) && $ip !== '' && IpUtils::checkIp($ip, $allow);
    }
}
