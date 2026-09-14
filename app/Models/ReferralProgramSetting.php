<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralProgramSetting extends Model
{
    public const SINGLETON_ID = 1;

    protected $fillable = ['id', 'enabled', 'reward_days', 'updated_by'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'reward_days' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
