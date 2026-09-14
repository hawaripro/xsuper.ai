<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
    public function subject() { return $this->morphTo(); }
}
