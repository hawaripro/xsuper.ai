<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMediaJob extends Model
{
    protected $guarded = ['id'];

    protected $hidden = [
        'provider_id', 'upstream_model_id', 'upstream_job_id', 'connection_fingerprint',
        'capability_snapshot', 'provider_bindings', 'normalized_inputs', 'input_assets', 'reference_asset_ids',
        'provider_result', 'provider_result_urls', 'asset_paths', 'processing_token', 'payload_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'provider_id' => 'integer', 'capability_revision_id' => 'integer',
            'price_tokens' => 'integer', 'tokens_reserved' => 'integer', 'poll_attempts' => 'integer',
            'capability_snapshot' => 'array', 'provider_bindings' => 'array', 'normalized_inputs' => 'array',
            'input_assets' => 'array', 'reference_asset_ids' => 'array', 'provider_result' => 'json',
            'provider_result_urls' => 'array', 'asset_paths' => 'array', 'result_data' => 'json',
            'submitted_at' => 'datetime', 'result_received_at' => 'datetime', 'next_poll_at' => 'datetime',
            'processing_started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
