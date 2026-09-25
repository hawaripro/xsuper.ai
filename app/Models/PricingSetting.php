<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingSetting extends Model
{
    public const SINGLETON_ID = 1;

    protected $fillable = [
        'id',
        'margin_pct', 'llm_margin_pct', 'buffer_pct', 'payment_fee_pct', 'wallet_idr_per_usd',
        'default_cost_idr_per_usd', 'round_tokens', 'chat_output_cap', 'last_applied_at', 'last_applied_by',
    ];

    protected function casts(): array
    {
        return [
            'margin_pct' => 'float', 'llm_margin_pct' => 'float', 'buffer_pct' => 'float', 'payment_fee_pct' => 'float',
            'wallet_idr_per_usd' => 'integer', 'default_cost_idr_per_usd' => 'integer',
            'round_tokens' => 'boolean', 'chat_output_cap' => 'integer', 'last_applied_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => self::SINGLETON_ID], [
            'margin_pct' => 40, 'llm_margin_pct' => null, 'buffer_pct' => 10, 'payment_fee_pct' => 1,
            'wallet_idr_per_usd' => (int) config('deposits.idr_per_usd', 16000),
            'default_cost_idr_per_usd' => 19000, 'round_tokens' => true, 'chat_output_cap' => 8192,
        ]);
    }
}
