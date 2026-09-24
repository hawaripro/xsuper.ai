<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends Model
{
    protected $fillable = [
        'user_id', 'title', 'model', 'conversation_key', 'workspace_id',
        'pinned', 'title_is_custom', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'workspace_id' => 'integer',
            'pinned' => 'boolean',
            'title_is_custom' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(ChatWorkspace::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }
}
