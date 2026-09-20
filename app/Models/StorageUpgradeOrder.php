<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageUpgradeOrder extends Model
{
    protected $fillable = [
        'user_id', 'plan_key', 'label', 'extra_bytes', 'days', 'price', 'payment_method',
        'payment_reference', 'payment_expires_at', 'payment_confirmed_at', 'status', 'note',
        'approved_at', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'extra_bytes' => 'integer',
            'days' => 'integer',
            'price' => 'integer',
            'payment_expires_at' => 'datetime',
            'payment_confirmed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
