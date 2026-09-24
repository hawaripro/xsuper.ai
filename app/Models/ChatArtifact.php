<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatArtifact extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'conversation_id', 'title', 'kind', 'version', 'current_revision_id',
        'source_message_id', 'source_operation_id',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'source_message_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ChatArtifactRevision::class, 'artifact_id')->orderByDesc('version');
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(ChatArtifactRevision::class, 'current_revision_id');
    }
}
