<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsEvent extends Model
{
    protected $fillable = ['user_id', 'name', 'session_id', 'properties', 'path'];
    protected function casts(): array { return ['properties' => 'array']; }
    public function user() { return $this->belongsTo(User::class); }
}
