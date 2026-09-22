<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModelProfile extends Model
{
    protected $hidden = ['upstream_identity'];

    protected $fillable = [
        'provider_id', 'model_id', 'upstream_model_id', 'display_name', 'provider_name', 'category',
        'description_id', 'description_en', 'logo_url', 'context_window', 'max_output_tokens',
        'is_enabled', 'is_available', 'capabilities', 'input_modalities', 'output_modalities',
        'badges', 'sort_order', 'last_seen_at', 'token_cost', 'generation_config',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_available' => 'boolean',
            'capabilities' => 'array',
            'input_modalities' => 'array',
            'output_modalities' => 'array',
            'badges' => 'array',
            'context_window' => 'integer',
            'max_output_tokens' => 'integer',
            'token_cost' => 'integer',
            'generation_config' => 'array',
            'sort_order' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    public function provider()
    {
        return $this->belongsTo(AiProviderProfile::class, 'provider_id');
    }

    public function capabilityRevisions()
    {
        return $this->hasMany(MediaCapabilityRevision::class, 'ai_model_profile_id');
    }
}
