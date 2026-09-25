<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioJob extends Model
{
    protected $hidden = [
        'provider_id', 'upstream_model_id', 'upstream_job_id', 'connection_fingerprint',
        'generation_config', 'provider_prompt', 'outputs', 'processing_token',
        'provider_result_urls',
        'routing_identity', 'dedup_key', 'payload_fingerprint', 'reference_asset_ids',
    ];

    protected $fillable = [
        'user_id', 'job_id', 'model', 'mode', 'prompt', 'provider_prompt', 'voice', 'speed',
        'duration', 'tempo', 'provider_id', 'upstream_model_id', 'upstream_job_id',
        'connection_fingerprint', 'generation_config', 'status', 'stage', 'outputs',
        'provider_result_urls',
        'error_message', 'billing_mode',
        'billing_status', 'billing_reference_id', 'tokens_reserved', 'submitted_at',
        'next_poll_at', 'processing_started_at', 'processing_token', 'completed_at', 'poll_attempts',
        'capability_revision_id', 'routing_identity', 'price_tokens', 'dedup_key', 'payload_fingerprint',
        'reference_asset_ids', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'speed' => 'float',
            'duration' => 'integer',
            'tempo' => 'integer',
            'outputs' => 'array',
            'provider_result_urls' => 'array',
            'tokens_reserved' => 'integer',
            'poll_attempts' => 'integer',
            'generation_config' => 'array',
            'capability_revision_id' => 'integer', 'price_tokens' => 'integer',
            'reference_asset_ids' => 'array', 'settings' => 'array',
            'submitted_at' => 'datetime',
            'next_poll_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
