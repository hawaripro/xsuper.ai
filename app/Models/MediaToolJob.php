<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaToolJob extends Model
{
    protected $hidden = ['source_url', 'lease_token', 'heartbeat_at', 'cancel_requested_at', 'dispatched_at'];

    protected $fillable = [
        'user_id', 'job_id', 'kind', 'status', 'stage', 'progress', 'title', 'source_url',
        'input_name', 'format', 'mime_type', 'size_bytes', 'duration', 'error_message',
        'lease_token', 'heartbeat_at', 'cancel_requested_at', 'dispatched_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_url' => 'encrypted',
            'progress' => 'float',
            'duration' => 'float',
            'size_bytes' => 'integer',
            'heartbeat_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
