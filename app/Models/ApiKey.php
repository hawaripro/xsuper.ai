<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    protected $fillable = [
        'user_id', 'key', 'name', 'is_active', 'allowed_models',
        'rate_limit', 'total_requests', 'last_used_at', 'expires_at',
    ];

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

    public static function generate(int $userId, string $name = 'Default', array $options = []): self
    {
        return static::create([
            'user_id' => $userId,
            'key' => 'ultrai-' . Str::random(48),
            'name' => $name,
            'is_active' => true,
            'rate_limit' => $options['rate_limit'] ?? 60,
            'allowed_models' => $options['allowed_models'] ?? null,
            'expires_at' => $options['expires_at'] ?? null,
        ]);
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
        return substr($this->key, 0, 12) . '...' . substr($this->key, -6);
    }
}
