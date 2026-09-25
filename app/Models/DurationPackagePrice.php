<?php

namespace App\Models;

use App\Services\Pricing\PricingEngine;
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
        'bonus_tokens', 'bonus_wallet_microusd', 'storage_bytes',
    ];

    protected function casts(): array
    {
        return [
            'price_idr' => 'integer',
            'price_usd' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'bonus_tokens' => 'integer',
            'bonus_wallet_microusd' => 'integer',
            'storage_bytes' => 'integer',
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
                'price_usd' => $override ? (float) $override->price_usd : self::defaultUsd($package['price']),
                'is_active' => $override?->is_active ?? true,
                'sort_order' => $override?->sort_order ?? array_search($id, array_keys(DurationOrder::PACKAGES), true),
                'bonus_tokens' => (int) ($override?->bonus_tokens ?? 0),
                'bonus_wallet_microusd' => (int) ($override?->bonus_wallet_microusd ?? 0),
                'bonus_wallet_usd' => number_format(($override?->bonus_wallet_microusd ?? 0) / 1_000_000, 2, '.', ''),
                'storage_bytes' => (int) ($override?->storage_bytes ?? 0),
                'storage_gb' => ($override?->storage_bytes ?? 0) / (1024 ** 3),
            ];
        }

        uasort($catalog, fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

        return $catalog;
    }

    public static function defaultUsd(int $priceIdr): float
    {
        return app(PricingEngine::class)->packageUsd($priceIdr);
    }
}
