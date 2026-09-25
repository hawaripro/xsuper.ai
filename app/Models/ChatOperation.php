<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ChatOperation extends Model
{
    use HasUuids;

    public const ACTIVE = ['queued', 'streaming'];

    protected $guarded = [];

    protected $hidden = ['fingerprint', 'context_snapshot', 'billing'];

    protected function casts(): array
    {
        return [
            'context_snapshot' => 'array',
            'attachment_ids' => 'array',
            'usage' => 'array',
            'billing' => 'array',
            'user_message_id' => 'integer',
            'assistant_message_id' => 'integer',
            'retry_of' => 'integer',
            'continuation_of' => 'integer',
            'stop_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'usage_recorded_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
