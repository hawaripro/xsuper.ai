<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProviderProfile extends Model
{
    protected $hidden = ['api_key', 'base_url', 'last_error'];

    protected $fillable = [
        'slug', 'name', 'status', 'is_enabled', 'capabilities', 'last_checked_at', 'last_error',
        'protocol', 'base_url', 'api_key', 'api_version',
    ];

    protected $attributes = ['protocol' => 'openai', 'api_version' => '2023-06-01'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'capabilities' => 'array',
            'last_checked_at' => 'datetime',
            'api_key' => 'encrypted',
        ];
    }

    public function adminPayload(): array
    {
        $saved = $this->base_url !== null;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status,
            'is_enabled' => $this->is_enabled,
            'capabilities' => $this->capabilities ?? [],
            'models_count' => (int) ($this->models_count ?? $this->models()->count()),
            'last_checked_at' => $this->last_checked_at?->toISOString(),
            'protocol' => $saved ? $this->protocol : 'openai',
            'base_url' => $saved ? $this->base_url : null,
            'api_version' => $this->api_version,
            'has_api_key' => $saved
                ? ! empty($this->getRawOriginal('api_key'))
                : is_string(config('services.ai_proxy.key')) && trim(config('services.ai_proxy.key')) !== '',
            'configuration_source' => $saved ? 'admin' : 'environment',
        ];
    }

    public function models()
    {
        return $this->hasMany(AiModelProfile::class, 'provider_id');
    }
}
