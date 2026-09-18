<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImageJob extends Model
{
    protected $hidden = ['asset_paths'];

    protected $fillable = ['user_id', 'job_id', 'model', 'prompt', 'size', 'quantity', 'status', 'stage', 'result_urls', 'error_message', 'billing_reserved_microusd', 'billing_reference_id', 'billing_status', 'billing_mode', 'tokens_reserved', 'asset_paths'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'result_urls' => 'array', 'asset_paths' => 'array', 'billing_reserved_microusd' => 'integer', 'tokens_reserved' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
