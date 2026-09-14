<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['open', 'in_progress', 'waiting_on_member', 'resolved', 'closed'];

    protected $fillable = [
        'user_id',
        'assigned_to',
        'subject',
        'category',
        'priority',
        'status',
        'last_replied_at',
    ];

    protected function casts(): array
    {
        return ['last_replied_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages()
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id')->oldest('id');
    }
}
