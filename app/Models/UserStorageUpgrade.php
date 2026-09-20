<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserStorageUpgrade extends Model
{
    protected $fillable = [
        'user_id', 'plan_key', 'extra_bytes', 'starts_at', 'expires_at', 'order_id',
    ];

    protected function casts(): array
    {
        return [
            'extra_bytes' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('starts_at', '<=', now())->where('expires_at', '>', now());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
