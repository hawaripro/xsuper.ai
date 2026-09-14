<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    protected $fillable = ['referrer_id', 'referred_id', 'code', 'status', 'attributed_at', 'qualified_at'];

    protected function casts(): array
    {
        return ['attributed_at' => 'datetime', 'qualified_at' => 'datetime'];
    }

    public function referrer() { return $this->belongsTo(User::class, 'referrer_id'); }
    public function referred() { return $this->belongsTo(User::class, 'referred_id'); }
    public function rewards() { return $this->hasMany(ReferralReward::class); }
}
