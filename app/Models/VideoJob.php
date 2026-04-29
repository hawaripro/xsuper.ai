<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoJob extends Model
{
    protected $fillable = [
        'user_id', 'job_id', 'mode', 'prompt', 'model', 'aspect_ratio',
        'duration', 'tokens_used', 'settings', 'status', 'video_url',
        'thumbnail_url', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
