<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageUpgradePlan extends Model
{
    protected $fillable = [
        'key', 'label', 'extra_bytes', 'days', 'price_idr', 'price_usd', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'extra_bytes' => 'integer',
            'days' => 'integer',
            'price_idr' => 'integer',
            'price_usd' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Active tiers a member can purchase, cheapest first. */
    public static function catalog(): array
    {
        return self::query()->where('is_active', true)
            ->orderBy('sort_order')->orderBy('price_idr')->get()->map->only([
                'key', 'label', 'extra_bytes', 'days', 'price_idr', 'price_usd',
            ])->all();
    }
}
