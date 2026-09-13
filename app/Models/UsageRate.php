<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class UsageRate extends Model
{
    protected $fillable = [
        'service',
        'meter',
        'model',
        'label',
        'unit',
        'price_idr',
        'price_usd',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_idr' => 'decimal:6',
            'price_usd' => 'decimal:8',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function publicCatalog(): array
    {
        if (! Schema::hasTable('usage_rates')) {
            return [];
        }

        return static::active()
            ->orderBy('sort_order')
            ->orderBy('service')
            ->orderBy('label')
            ->get()
            ->groupBy('service')
            ->map(fn ($rates) => $rates->values()->toArray())
            ->all();
    }

    public static function activeForModel(string $service, string $model)
    {
        if (! Schema::hasTable('usage_rates')) {
            return collect();
        }

        return static::active()
            ->where('service', $service)
            ->where('model', $model)
            ->get()
            ->keyBy('meter');
    }

    public static function forMeter(string $service, string $meter, string $model): ?self
    {
        return static::activeForModel($service, $model)->get($meter);
    }

    public function costMicrousd(int $quantity): int
    {
        if ($quantity <= 0) {
            return 0;
        }

        return match ($this->meter) {
            'input_tokens', 'output_tokens' => (int) ceil(((float) $this->price_usd * $quantity * 1_000_000) / 1_000_000),
            default => (int) ceil((float) $this->price_usd * $quantity * 1_000_000),
        };
    }
}
