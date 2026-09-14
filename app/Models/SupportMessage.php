<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportMessage extends Model
{
    protected $fillable = ['ticket_id', 'user_id', 'body', 'is_staff'];
    protected function casts(): array { return ['is_staff' => 'boolean']; }
    public function ticket() { return $this->belongsTo(SupportTicket::class, 'ticket_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
