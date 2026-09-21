<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImageJob extends Model
{
    protected $hidden = [
        'asset_paths', 'provider_id', 'upstream_model_id', 'upstream_job_id',
        'connection_fingerprint', 'generation_config', 'processing_token',
    ];

    protected $fillable = [
        'user_id', 'job_id', 'model', 'prompt', 'size', 'quantity', 'status', 'stage',
        'result_urls', 'error_message', 'billing_reserved_microusd', 'billing_reference_id',
        'billing_status', 'billing_mode', 'tokens_reserved', 'asset_paths',
        'provider_id', 'upstream_model_id', 'upstream_job_id', 'connection_fingerprint',
        'generation_config', 'submitted_at', 'next_poll_at', 'processing_started_at',
        'processing_token', 'poll_attempts', 'completed_at',
        'capability_revision_id', 'routing_identity', 'price_tokens', 'dedup_key', 'payload_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'result_urls' => 'array', 'asset_paths' => 'array',
            'billing_reserved_microusd' => 'integer', 'tokens_reserved' => 'integer',
            'poll_attempts' => 'integer', 'generation_config' => 'array',
            'submitted_at' => 'datetime', 'next_poll_at' => 'datetime',
            'processing_started_at' => 'datetime', 'completed_at' => 'datetime',
            'capability_revision_id' => 'integer', 'price_tokens' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
