<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralReward extends Model
{
    protected $fillable = ['referral_id', 'user_id', 'kind', 'days', 'wallet_microusd', 'reference', 'awarded_at'];

    protected function casts(): array
    {
        return ['days' => 'integer', 'wallet_microusd' => 'integer', 'awarded_at' => 'datetime'];
    }

    public function referral() { return $this->belongsTo(Referral::class); }
    public function user() { return $this->belongsTo(User::class); }
}
