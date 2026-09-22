<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreeDJob extends Model
{
    protected $table = 'three_d_jobs';

    protected $hidden = [
        'provider_id', 'upstream_model_id', 'upstream_job_id', 'connection_fingerprint',
        'generation_config', 'model_path', 'provider_result_url', 'processing_token',
        'routing_identity', 'dedup_key', 'payload_fingerprint', 'reference_asset_ids',
    ];

    protected $fillable = [
        'user_id', 'job_id', 'model', 'model_label', 'operation', 'provider_id', 'upstream_model_id',
        'upstream_job_id', 'connection_fingerprint', 'generation_config', 'capability_revision_id',
        'routing_identity', 'price_tokens', 'dedup_key', 'payload_fingerprint', 'reference_asset_ids',
        'settings', 'status', 'stage', 'model_url', 'model_path', 'provider_result_url', 'mime_type',
        'size_bytes', 'previewable', 'preview_unavailable_reason', 'error_message', 'billing_mode',
        'billing_status', 'billing_reference_id', 'tokens_reserved', 'submitted_at', 'next_poll_at',
        'processing_started_at', 'processing_token', 'completed_at', 'poll_attempts',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'provider_id' => 'integer', 'capability_revision_id' => 'integer',
            'price_tokens' => 'integer', 'size_bytes' => 'integer', 'tokens_reserved' => 'integer',
            'poll_attempts' => 'integer', 'previewable' => 'boolean', 'generation_config' => 'array',
            'reference_asset_ids' => 'array', 'settings' => 'array', 'submitted_at' => 'datetime',
            'next_poll_at' => 'datetime', 'processing_started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
