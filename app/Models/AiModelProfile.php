<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModelProfile extends Model
{
    protected $fillable = ['provider_id', 'model_id', 'display_name', 'category', 'tier', 'is_enabled', 'capabilities', 'last_seen_at'];
    protected function casts(): array { return ['is_enabled' => 'boolean', 'capabilities' => 'array', 'last_seen_at' => 'datetime']; }
    public function provider() { return $this->belongsTo(AiProviderProfile::class, 'provider_id'); }
}
