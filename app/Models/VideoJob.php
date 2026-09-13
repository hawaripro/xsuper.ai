<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoJob extends Model
{
    protected $fillable = [
        'user_id', 'job_id', 'mode', 'prompt', 'model', 'aspect_ratio',
        'duration', 'tokens_used', 'billing_reserved_microusd', 'billing_reference_id',
        'billing_status', 'settings', 'status', 'video_url', 'thumbnail_url', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'billing_reserved_microusd' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
