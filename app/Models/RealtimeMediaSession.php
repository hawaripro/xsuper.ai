<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RealtimeMediaSession extends Model
{
    use HasUuids;

    /** Rows whose inputs/recordings are protected and whose lease may still be heartbeated. */
    public const ACTIVE_STATUSES = ['preparing', 'negotiating', 'connected'];

    /** Ended attempts; a terminal session is never reopened, renewed or reconnected. */
    public const TERMINAL_STATUSES = ['closed', 'exhausted', 'expired', 'failed'];

    protected $guarded = ['id'];

    protected $hidden = [
        'request_key', 'payload_fingerprint', 'connection_fingerprint', 'offer_fingerprint', 'session_token_hash',
        'provider_id', 'upstream_session_id', 'answer_sdp', 'configure_message', 'capability_snapshot', 'provider_bindings',
        'normalized_inputs', 'asset_ids', 'recording_asset_ids', 'control_updates', 'billing_reservation',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'provider_id' => 'integer', 'capability_revision_id' => 'integer',
            'price_tokens' => 'integer', 'max_session_seconds' => 'integer', 'next_prompt_version' => 'integer',
            'heartbeat_failures' => 'integer', 'capability_snapshot' => 'array', 'provider_bindings' => 'array',
            'normalized_inputs' => 'array', 'asset_ids' => 'array', 'recording_asset_ids' => 'array',
            'control_updates' => 'array', 'billing_reservation' => 'array',
            'answer_sdp' => 'encrypted', 'configure_message' => 'encrypted:array',
            'offered_at' => 'datetime', 'accepted_at' => 'datetime', 'expires_at' => 'datetime',
            'last_heartbeat_at' => 'datetime', 'configured_observed_at' => 'datetime', 'closed_at' => 'datetime',
            'close_requested_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
