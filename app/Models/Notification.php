<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = ['user_id', 'kind', 'title', 'body', 'action_url', 'metadata', 'read_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'read_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
