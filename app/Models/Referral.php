<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    public const STATUSES = ['attributed', 'flagged', 'qualified', 'rejected'];

    protected $fillable = [
        'referrer_id', 'referred_id', 'code', 'status', 'attributed_at', 'qualified_at',
        'referred_ip', 'referred_device_hash', 'risk_level', 'risk_reasons', 'reviewed_at', 'reviewed_by', 'review_note',
    ];

    protected function casts(): array
    {
        return ['attributed_at' => 'datetime', 'qualified_at' => 'datetime', 'reviewed_at' => 'datetime', 'risk_reasons' => 'array'];
    }

    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function referrer() { return $this->belongsTo(User::class, 'referrer_id'); }
    public function referred() { return $this->belongsTo(User::class, 'referred_id'); }
    public function rewards() { return $this->hasMany(ReferralReward::class); }
}
