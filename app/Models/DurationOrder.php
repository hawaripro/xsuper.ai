<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DurationOrder extends Model
{
    protected $fillable = [
        'user_id', 'package', 'days', 'price', 'payment_method', 'payment_reference',
        'payment_expires_at', 'payment_confirmed_at', 'status', 'note', 'approved_at', 'approved_by',
    ];
    protected function casts(): array
    {
        return [
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

    public const PACKAGES = [
        '1_day'      => ['label' => '1 Hari', 'days' => 1, 'price' => 5000],
        '1_week'     => ['label' => '1 Minggu', 'days' => 7, 'price' => 20000],
        '1_month'    => ['label' => '1 Bulan', 'days' => 30, 'price' => 55000],
        '3_months'   => ['label' => '3 Bulan', 'days' => 90, 'price' => 135000],
        '6_months'   => ['label' => '6 Bulan', 'days' => 180, 'price' => 299000],
        '12_months'  => ['label' => '12 Bulan', 'days' => 365, 'price' => 499000],
    ];
}
