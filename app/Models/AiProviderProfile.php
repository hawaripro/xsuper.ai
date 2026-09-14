<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProviderProfile extends Model
{
    protected $hidden = ['last_error'];
    protected $fillable = ['slug', 'name', 'status', 'is_enabled', 'capabilities', 'last_checked_at', 'last_error'];
    protected function casts(): array { return ['is_enabled' => 'boolean', 'capabilities' => 'array', 'last_checked_at' => 'datetime']; }
    public function models() { return $this->hasMany(AiModelProfile::class, 'provider_id'); }
}
