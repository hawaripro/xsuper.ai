<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioJob extends Model
{
    protected $hidden = [
        'provider_id', 'upstream_model_id', 'upstream_job_id', 'connection_fingerprint',
        'generation_config', 'provider_prompt', 'audio_path', 'processing_token',
    ];

    protected $fillable = [
        'user_id', 'job_id', 'model', 'mode', 'prompt', 'provider_prompt', 'voice', 'speed',
        'duration', 'tempo', 'provider_id', 'upstream_model_id', 'upstream_job_id',
        'connection_fingerprint', 'generation_config', 'status', 'stage', 'audio_url',
        'audio_path', 'mime_type', 'size_bytes', 'error_message', 'billing_mode',
        'billing_status', 'billing_reference_id', 'tokens_reserved', 'submitted_at',
        'next_poll_at', 'processing_started_at', 'processing_token', 'completed_at', 'poll_attempts',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'speed' => 'float',
            'duration' => 'integer',
            'tempo' => 'integer',
            'size_bytes' => 'integer',
            'tokens_reserved' => 'integer',
            'poll_attempts' => 'integer',
            'generation_config' => 'array',
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
