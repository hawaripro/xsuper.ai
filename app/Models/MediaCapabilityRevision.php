<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaCapabilityRevision extends Model
{
    protected $table = 'media_capabilities';

    protected $fillable = [
        'ai_model_profile_id', 'operation', 'contract_version', 'revision', 'status',
        'definition', 'ui_metadata', 'source_schema_ref', 'source_hash', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'ui_metadata' => 'array',
            'contract_version' => 'integer',
            'revision' => 'integer',
        ];
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<MediaCapabilityRevision>  $query */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function model()
    {
        return $this->belongsTo(AiModelProfile::class, 'ai_model_profile_id');
    }
}
