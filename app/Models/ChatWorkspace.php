<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatWorkspace extends Model
{
    protected $fillable = ['user_id', 'name', 'notes', 'version', 'default_key'];

    protected $hidden = ['default_key'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'version' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class, 'workspace_id');
    }
}
