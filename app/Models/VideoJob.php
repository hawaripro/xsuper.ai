<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoJob extends Model
{
    protected $hidden = ['provider_id', 'upstream_model_id', 'upstream_job_id', 'connection_fingerprint', 'generation_config', 'reference_path', 'reference_mime_type', 'reference_asset_ids'];

    protected $fillable = [
        'user_id', 'job_id', 'mode', 'prompt', 'model', 'aspect_ratio',
        'duration', 'tokens_used', 'billing_reserved_microusd', 'billing_reference_id',
        'billing_status', 'settings', 'status', 'video_url', 'thumbnail_url', 'error_message',
        'provider_id', 'upstream_model_id', 'upstream_job_id', 'connection_fingerprint', 'stage',
        'improved_prompt', 'moderation_reason_code', 'billing_mode', 'tokens_reserved',
        'generation_config', 'submitted_at', 'next_poll_at', 'processing_started_at',
        'completed_at', 'poll_attempts',
        'pro_mode', 'has_reference', 'reference_path', 'reference_mime_type',
        'capability_revision_id', 'routing_identity', 'price_tokens', 'dedup_key', 'payload_fingerprint', 'reference_asset_ids',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'billing_reserved_microusd' => 'integer',
            'tokens_reserved' => 'integer',
            'poll_attempts' => 'integer',
            'generation_config' => 'array',
            'pro_mode' => 'boolean',
            'has_reference' => 'boolean',
            'submitted_at' => 'datetime',
            'next_poll_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'capability_revision_id' => 'integer', 'price_tokens' => 'integer', 'reference_asset_ids' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
