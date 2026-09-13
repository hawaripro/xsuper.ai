<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class DurationPackagePrice extends Model
{
    protected $fillable = [
        'package',
        'price_idr',
        'price_usd',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_idr' => 'integer',
            'price_usd' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public static function catalog(): array
    {
        $overrides = Schema::hasTable('duration_package_prices')
            ? static::query()->get()->keyBy('package')
            : collect();

        $catalog = [];
        foreach (DurationOrder::PACKAGES as $id => $package) {
            $override = $overrides->get($id);
            $catalog[$id] = [
                ...$package,
                'price' => $override?->price_idr ?? $package['price'],
                'price_idr' => $override?->price_idr ?? $package['price'],
                'price_usd' => $override ? (float) $override->price_usd : self::defaultUsd($id, $package['price']),
                'is_active' => $override?->is_active ?? true,
                'sort_order' => $override?->sort_order ?? array_search($id, array_keys(DurationOrder::PACKAGES), true),
            ];
        }

        uasort($catalog, fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

        return $catalog;
    }

    public static function defaultUsd(string $package, int $priceIdr): float
    {
        return (float) config("pricing.default_usd.{$package}", round($priceIdr / 16000, 2));
    }
}
