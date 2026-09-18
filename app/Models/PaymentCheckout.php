<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentCheckout extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'reference';

    protected $keyType = 'string';

    protected $fillable = [
        'reference', 'user_id', 'package', 'payment_method', 'amount_idr', 'expires_at', 'used_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_idr' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
