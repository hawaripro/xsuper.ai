<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ChatArtifactRevision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $hidden = ['storage_disk', 'storage_path'];

    protected $fillable = [
        'artifact_id', 'user_id', 'version', 'filename', 'mime', 'storage_disk', 'storage_path',
        'size_bytes', 'content_bytes', 'generated_job_id', 'generated_output_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'size_bytes' => 'integer', 'content_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Artifact revisions are immutable. Create a new revision instead.');
        });
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ChatArtifact::class, 'artifact_id');
    }
}
