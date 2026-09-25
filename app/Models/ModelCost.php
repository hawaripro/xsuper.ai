<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelCost extends Model
{
    public const SOURCES = ['runware_docs', 'fal_api', 'kinovi_docs', 'reference', 'manual'];
    public const STATUSES = ['ok', 'estimate', 'unknown'];
    public const UNITS = ['generation', 'second', 'request', 'session'];

    protected $fillable = [
        'ai_model_profile_id', 'source', 'currency', 'input_per_million', 'output_per_million',
        'cache_read_per_million', 'cache_write_per_million', 'unit', 'unit_cost', 'status', 'basis_note',
        'reference_id', 'price_locked', 'fetched_at', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'input_per_million' => 'decimal:10', 'output_per_million' => 'decimal:10',
            'cache_read_per_million' => 'decimal:10', 'cache_write_per_million' => 'decimal:10',
            'unit_cost' => 'decimal:10', 'price_locked' => 'boolean', 'fetched_at' => 'datetime',
        ];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModelProfile::class, 'ai_model_profile_id');
    }

    public function isManual(): bool
    {
        return $this->source === 'manual';
    }
}
