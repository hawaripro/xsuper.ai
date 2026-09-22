<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProviderProfile extends Model
{
    protected $hidden = ['api_key', 'base_url', 'last_error'];

    protected $fillable = [
        'slug', 'name', 'status', 'is_enabled', 'capabilities', 'last_checked_at', 'last_error',
        'protocol', 'base_url', 'api_key', 'api_version',
        'catalog_discovered_at', 'authenticated_at',
    ];

    protected $attributes = ['protocol' => 'openai', 'api_version' => '2023-06-01'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'capabilities' => 'array',
            'last_checked_at' => 'datetime',
            'api_key' => 'encrypted',
            'catalog_discovered_at' => 'datetime',
            'authenticated_at' => 'datetime',
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
            'catalog_discovered_at' => $this->catalog_discovered_at?->toISOString(),
            'authenticated_at' => $this->authenticated_at?->toISOString(),
            'verification' => [
                'catalog_source' => $this->protocol === 'kinovi' ? 'static_documentation' : 'remote_catalog',
                'authenticated' => $this->authenticated_at !== null,
                'generation_verified' => false,
            ],
            'protocol' => $saved ? $this->protocol : 'openai',
            'base_url' => $saved ? $this->base_url : null,
            'api_version' => $this->api_version,
            'has_api_key' => $saved
                ? ! empty($this->getRawOriginal('api_key'))
                : is_string(config('services.ai_proxy.key')) && trim(config('services.ai_proxy.key')) !== '',
            'configuration_source' => $saved ? 'admin' : 'environment',
            'last_error' => $this->sanitizedLastError(),
        ];
    }

    /** Admin-only failure reason with secrets/signed material redacted (never raw exceptions). */
    private function sanitizedLastError(): ?string
    {
        $error = $this->last_error;
        if (! is_string($error) || trim($error) === '') {
            return null;
        }
        $error = preg_replace('/([Bb]earer\s+)[A-Za-z0-9._\-]+/', '$1[redacted]', $error);
        $error = preg_replace('/([?&](?:signature|sig|token|api[_-]?key|X-Amz-Signature)=)[^&\s]+/i', '$1[redacted]', (string) $error);

        return mb_substr((string) $error, 0, 500);
    }

    public function models()
    {
        return $this->hasMany(AiModelProfile::class, 'provider_id');
    }
}
