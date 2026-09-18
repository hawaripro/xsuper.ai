<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositOrder extends Model
{
    public const KIND_TOKENS = 'tokens';

    public const KIND_WALLET = 'wallet';

    public const STATUS_CHECKOUT = 'checkout';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const KINDS = [self::KIND_TOKENS, self::KIND_WALLET];

    public const STATUSES = [self::STATUS_CHECKOUT, self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    protected $fillable = [
        'payment_reference',
        'user_id',
        'kind',
        'package_code',
        'package_name',
        'base_tokens',
        'bonus_tokens',
        'total_tokens',
        'amount_idr',
        'credit_microusd',
        'idr_per_usd',
        'payment_method',
        'status',
        'expires_at',
        'confirmed_at',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'base_tokens' => 'integer',
            'bonus_tokens' => 'integer',
            'total_tokens' => 'integer',
            'amount_idr' => 'integer',
            'credit_microusd' => 'integer',
            'idr_per_usd' => 'integer',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function displayStatus(): string
    {
        if ($this->status === self::STATUS_CHECKOUT && $this->expires_at->isPast()) {
            return 'expired';
        }

        return $this->status;
    }

    public function toApiArray(bool $includeUser = false): array
    {
        $payload = [
            'id' => $this->id,
            'payment_reference' => $this->payment_reference,
            'kind' => $this->kind,
            'package_code' => $this->package_code,
            'package_name' => $this->package_name,
            'base_tokens' => $this->base_tokens,
            'bonus_tokens' => $this->bonus_tokens,
            'total_tokens' => $this->total_tokens,
            'amount_idr' => $this->amount_idr,
            'credit_microusd' => $this->credit_microusd,
            'credit_usd' => $this->credit_microusd / 1_000_000,
            'idr_per_usd' => $this->idr_per_usd,
            'payment_method' => $this->payment_method,
            'qr_image_url' => config('deposits.qris_image_url'),
            'expires_at' => $this->expires_at->toISOString(),
            'status' => $this->displayStatus(),
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        if ($includeUser) {
            $payload['user'] = $this->relationLoaded('user') && $this->user
                ? $this->user->only(['id', 'name', 'email'])
                : null;
        }

        return $payload;
    }
}
