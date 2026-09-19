<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    protected $fillable = [
        'user_id', 'key_hash', 'key_prefix', 'name', 'is_active', 'allowed_models',
        'rate_limit', 'total_requests', 'last_used_at', 'expires_at',
    ];

    /** Transient plaintext key, set only at create/regenerate time for one-time display. */
    public ?string $plainKey = null;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allowed_models' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function findByPlainKey(string $key): ?self
    {
        return static::where('key_hash', self::hashKey($key))->first();
    }

    public static function generate(int $userId, string $name = 'Default', array $options = []): self
    {
        $plain = 'ultrai-'.Str::random(48);
        $model = static::create([
            'user_id' => $userId,
            'key_hash' => self::hashKey($plain),
            'key_prefix' => substr($plain, 0, 12),
            'name' => $name,
            'is_active' => true,
            'rate_limit' => $options['rate_limit'] ?? 60,
            'allowed_models' => $options['allowed_models'] ?? null,
            'expires_at' => $options['expires_at'] ?? null,
        ]);
        $model->plainKey = $plain;

        return $model;
    }

    /** Rotate the key in place, returning the model carrying the new plaintext once. */
    public function regenerateKey(): self
    {
        $plain = 'ultrai-'.Str::random(48);
        $this->key_hash = self::hashKey($plain);
        $this->key_prefix = substr($plain, 0, 12);
        $this->save();
        $this->plainKey = $plain;

        return $this;
    }

    public function isValid(): bool
    {
        if (!$this->is_active) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
    }

    public function recordUsage(): void
    {
        $this->increment('total_requests');
        $this->update(['last_used_at' => now()]);
    }

    public function maskedKey(): string
    {
        return ($this->key_prefix ?: 'ultrai-').'…';
    }
}
