<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenReservation extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'user_id',
        'reference_id',
        'service',
        'model',
        'quantity',
        'unit_tokens',
        'amount_tokens',
        'billing_mode',
        'status',
        'settlement_details',
        'settled_at',
        'released_at',
        'release_description',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_tokens' => 'integer',
            'amount_tokens' => 'integer',
            'settlement_details' => 'array',
            'settled_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payload(): array
    {
        return [
            'reference_id' => $this->reference_id,
            'amount_tokens' => $this->amount_tokens,
            'unit_tokens' => $this->unit_tokens,
            'billing_mode' => $this->billing_mode,
        ];
    }
}
