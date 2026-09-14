<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImageJob extends Model
{
    protected $fillable = ['user_id', 'job_id', 'model', 'prompt', 'size', 'quantity', 'status', 'result_urls', 'error_message', 'billing_reserved_microusd', 'billing_reference_id', 'billing_status'];
    protected function casts(): array { return ['quantity' => 'integer', 'result_urls' => 'array', 'billing_reserved_microusd' => 'integer']; }
    public function user() { return $this->belongsTo(User::class); }
}
