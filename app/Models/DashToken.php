<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DashToken extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Issue a fresh random dash token for the admin, replacing any prior ones,
     * and return the raw value (stored only as a hash) for the cookie.
     */
    public static function issueFor(int $userId, int $days = 7): string
    {
        static::where('user_id', $userId)->delete();
        static::where('expires_at', '<', now())->delete();

        $raw = Str::random(64);
        static::create([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addDays($days),
            'created_at' => now(),
        ]);

        return $raw;
    }

    public static function revokeFor(?int $userId): void
    {
        if ($userId !== null) {
            static::where('user_id', $userId)->delete();
        }
    }
}
