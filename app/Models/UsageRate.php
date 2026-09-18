<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UsageRate extends Model
{
    public const API_METERS = ['input_tokens', 'output_tokens', 'cache_read', 'cache_write'];

    public const SERVICES = ['api', 'image', 'video', 'audio'];

    public const METERS = [...self::API_METERS, 'unit'];

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
            'input_tokens', 'output_tokens', 'cache_read', 'cache_write' => (int) ceil((float) $this->price_usd * $quantity),
            default => (int) ceil((float) $this->price_usd * $quantity * 1_000_000),
        };
    }

    public static function assertValidState(iterable $rates, string $errorKey = 'rates'): void
    {
        $groups = collect($rates)->groupBy(fn (self $rate): string => $rate->service.'|'.$rate->model);
        foreach ($groups as $group) {
            foreach ($group as $rate) {
                $validMeter = $rate->service === 'api'
                    ? in_array($rate->meter, self::API_METERS, true)
                    : in_array($rate->service, ['image', 'video', 'audio'], true) && $rate->meter === 'unit';
                if (! $validMeter) {
                    throw ValidationException::withMessages([$errorKey => 'The meter does not match its service.']);
                }
                if ($rate->is_active && ($rate->price_idr === null || $rate->price_usd === null)) {
                    throw ValidationException::withMessages([$errorKey => 'Active rates require both IDR and USD prices.']);
                }
            }
            if ($group->first()->service !== 'api') {
                continue;
            }
            $active = $group->where('is_active', true)->keyBy('meter');
            if ($active->isNotEmpty() && (! $active->has('input_tokens') || ! $active->has('output_tokens'))) {
                throw ValidationException::withMessages([$errorKey => 'Active API pricing requires both input and output rates.']);
            }
        }
    }
}
