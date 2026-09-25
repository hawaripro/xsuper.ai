<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'settings', 'summary'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'summary' => 'array'];
    }
}
