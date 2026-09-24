<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MediaAsset extends Model
{
    use HasUuids;

    protected $hidden = ['storage_disk', 'storage_path'];

    protected $fillable = [
        'user_id', 'media_type', 'role', 'storage_disk', 'storage_path',
        'size_bytes', 'mime', 'original_name', 'signature_ok', 'metadata', 'retention_status', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'signature_ok' => 'boolean',
            'metadata' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<MediaAsset>  $query */
    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
