<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TokenPackage extends Model
{
    protected $fillable = [
        'code',
        'name',
        'base_tokens',
        'bonus_tokens',
        'price_idr',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'base_tokens' => 'integer',
            'bonus_tokens' => 'integer',
            'price_idr' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getTotalTokensAttribute(): int
    {
        return $this->base_tokens + $this->bonus_tokens;
    }

    public function toCatalogArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'base_tokens' => $this->base_tokens,
            'bonus_tokens' => $this->bonus_tokens,
            'total_tokens' => $this->total_tokens,
            'price_idr' => $this->price_idr,
            'sort_order' => $this->sort_order,
        ];
    }
}
