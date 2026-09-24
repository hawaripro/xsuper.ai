<?php

namespace App\Models;

use App\Services\RealtimeMediaService;
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

    public function hasReviewedPrice(?int $tokenCost): bool
    {
        $pricing = $this->curation_overrides['pricing'] ?? [];
        // A realtime tariff buys one bounded session; it is never metered per second.
        $realtime = $this->operation === 'realtime_video';

        return $tokenCost !== null && $tokenCost > 0 && ($pricing['token_cost'] ?? null) === $tokenCost
            && in_array($pricing['unit'] ?? null, $realtime ? ['request', 'generation'] : ['request', 'generation', 'second'], true)
            && ($pricing['variable_configuration'] ?? false) === true
            && isset($pricing['reviewed_by'], $pricing['reviewed_at'])
            && (! $realtime || (int) ($pricing['max_session_seconds'] ?? 0) >= $this->executionMetadata()['max_session_seconds']);
    }

    public function executionMetadata(): ?array
    {
        if ($this->operation !== 'realtime_video') {
            return null;
        }
        $bindings = $this->session_seconds !== null
            ? ['max_session_seconds' => (int) $this->session_seconds]
            : ($this->provider_bindings ?? []);

        return ['transport' => 'realtime', 'max_session_seconds' => RealtimeMediaService::maxSessionSeconds($bindings)];
    }

    /** Deliberately admin-only; member presentation never serializes this model. */
    public function adminPayload(bool $full = true): array
    {
        $summary = [
            'id' => $this->id, 'operation' => $this->operation, 'revision' => $this->revision,
            'status' => $this->status, 'is_active' => $this->status === 'published',
            'previously_published' => $this->published_at !== null,
            'contract_version' => $this->contract_version,
            'compatible' => ($this->compatibility_report['compatible'] ?? false) === true,
            'reviewed' => $this->reviewed_at !== null,
            'price_review' => $this->curation_overrides['pricing'] ?? null,
            'execution' => $this->executionMetadata(),
            'blocker_count' => count($this->compatibility_report['blockers'] ?? []),
        ];

        return $full ? [...$summary,
            'definition' => $this->definition, 'source_schema' => $this->source_schema,
            'source_metadata' => $this->source_metadata, 'source_hash' => $this->source_hash,
            'compatibility_report' => $this->compatibility_report,
            'ui_metadata' => $this->ui_metadata, 'curation_overrides' => $this->curation_overrides,
            'created_at' => $this->created_at?->toISOString(), 'reviewed_at' => $this->reviewed_at?->toISOString(),
            'tested_at' => $this->tested_at?->toISOString(), 'published_at' => $this->published_at?->toISOString(),
            'source_evidence' => isset($this->source_metadata['source_evidence']) ? 'documented_supplement'
                : (is_array($this->source_schema) && $this->source_schema !== [] ? 'captured' : 'missing'),
            'generation_verification' => 'not_recorded',
        ] : $summary;
    }
}
