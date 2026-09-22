<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MediaCapabilityRevision extends Model
{
    protected $table = 'media_capabilities';

    protected $fillable = [
        'ai_model_profile_id', 'operation', 'contract_version', 'revision', 'status',
        'definition', 'ui_metadata', 'source_schema_ref', 'source_hash', 'created_by',
        'source_schema', 'source_metadata', 'provider_bindings', 'compatibility_report', 'curation_overrides',
        'reviewed_by', 'reviewed_at', 'tested_at', 'published_at', 'disabled_at',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'ui_metadata' => 'array',
            'contract_version' => 'integer',
            'revision' => 'integer',
            'source_schema' => 'array',
            'source_metadata' => 'array',
            'provider_bindings' => 'array',
            'compatibility_report' => 'array',
            'curation_overrides' => 'array',
            'reviewed_at' => 'datetime',
            'tested_at' => 'datetime',
            'published_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /** @param  Builder<MediaCapabilityRevision>  $query */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function model()
    {
        return $this->belongsTo(AiModelProfile::class, 'ai_model_profile_id');
    }

    /** Deliberately admin-only; member presentation never serializes this model. */
    public function adminPayload(bool $full = true): array
    {
        $summary = [
            'id' => $this->id, 'operation' => $this->operation, 'revision' => $this->revision,
            'status' => $this->status, 'is_active' => $this->status === 'published',
            'previously_published' => $this->published_at !== null,
            'blocker_count' => count($this->compatibility_report['blockers'] ?? []),
        ];

        return $full ? [...$summary,
            'definition' => $this->definition, 'source_schema' => $this->source_schema,
            'source_metadata' => $this->source_metadata, 'source_hash' => $this->source_hash,
            'compatibility_report' => $this->compatibility_report,
            'ui_metadata' => $this->ui_metadata, 'curation_overrides' => $this->curation_overrides,
            'created_at' => $this->created_at?->toISOString(), 'reviewed_at' => $this->reviewed_at?->toISOString(),
            'tested_at' => $this->tested_at?->toISOString(), 'published_at' => $this->published_at?->toISOString(),
        ] : $summary;
    }
}
