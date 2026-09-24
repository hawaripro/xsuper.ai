<?php

namespace App\Services;

use App\Models\ChatArtifact;
use App\Models\ChatArtifactRevision;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatArtifactService
{
    public const MAX_TEXT_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly ChatWorkspaceService $workspaces,
        private readonly StorageQuotaService $quota,
        private readonly WorkspaceMediaService $media,
    ) {}

    public function index(User $user, string $key): array
    {
        abort_unless($user->hasPermission('chat_history'), 403);
        $this->workspaces->resolveConversation($user, $key);

        return ChatArtifact::query()->where('user_id', $user->id)->where('conversation_id', $key)
            ->with('currentRevision')->orderByDesc('updated_at')->get()
            ->filter(fn (ChatArtifact $artifact) => $artifact->currentRevision !== null)
            ->map(fn (ChatArtifact $artifact) => $this->summary($artifact, $artifact->currentRevision))->values()->all();
    }

    public function create(User $user, string $key, array $data): array
    {
        abort_unless($user->hasPermission('chat_history'), 403);
        $storedPath = null;
        try {
            return DB::transaction(function () use ($user, $key, $data, &$storedPath) {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $conversation = $this->workspaces->resolveConversation($user, $key);
                $conversation->newQuery()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                [$messageId, $operationId] = $this->provenance($user, $key, $data);
                $generated = $data['generated_output'] ?? null;
                if ($generated !== null) {
                    if (array_key_exists('content', $data)) {
                        throw ValidationException::withMessages(['content' => 'Choose text content or one generated output, not both.']);
                    }
                    $output = $this->media->resolveOwnedOutput($user, $generated['job_id'], $generated['output_id']);
                    abort_unless(($output['conversation_id'] ?? null) === $key, 404);
                    $filename = $this->filename($data['filename'] ?? $output['name']);
                    $kind = $output['kind'];
                    $mime = $output['mime'];
                } else {
                    if (! array_key_exists('content', $data)) {
                        throw ValidationException::withMessages(['content' => 'Artifact content is required.']);
                    }
                    $filename = $this->filename($data['filename'] ?? '');
                    $kind = $data['kind'] ?? $this->inferKind($filename);
                    $mime = $this->textMime($kind, $filename, $data['mime'] ?? null);
                    $this->validateText($data['content'], $kind, $mime);
                    $this->quota->assertCanStore($user, strlen($data['content']));
                }
                $artifact = ChatArtifact::create([
                    'user_id' => $user->id, 'conversation_id' => $key,
                    'title' => trim($data['title'] ?? '') ?: $filename,
                    'kind' => $kind, 'version' => 1,
                    'source_message_id' => $messageId, 'source_operation_id' => $operationId,
                ]);
                if ($generated !== null) {
                    $revision = $artifact->revisions()->create([
                        'user_id' => $user->id, 'version' => 1, 'filename' => $filename, 'mime' => $mime,
                        'storage_disk' => 'local', 'storage_path' => null, 'size_bytes' => 0,
                        'content_bytes' => $output['size'] ?? $output['bytes'],
                        'generated_job_id' => $generated['job_id'], 'generated_output_id' => $generated['output_id'],
                        'created_at' => now(),
                    ]);
                } else {
                    $revision = $this->storeText($artifact, 1, $filename, $mime, $data['content'], $storedPath);
                }
                $artifact->update(['current_revision_id' => $revision->id]);

                return $this->present($user, $artifact, $revision);
            });
        } catch (Throwable $error) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $error;
        }
    }

    public function show(User $user, string $id, ?string $revisionId = null): array
    {
        $artifact = $this->owned($user, $id);
        $revision = $this->revision($artifact, $revisionId);

        return $this->present($user, $artifact, $revision);
    }

    public function revise(User $user, string $id, array $data): array
    {
        $storedPath = null;
        try {
            return DB::transaction(function () use ($user, $id, $data, &$storedPath) {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $artifact = $this->owned($user, $id, true);
                if ($artifact->version !== (int) $data['base_version']) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'This artifact has a newer saved revision. Your draft has not been overwritten.',
                        'code' => 'artifact_version_conflict', 'current_version' => $artifact->version,
                    ], 409));
                }
                $previous = $this->revision($artifact);
                if ($previous->generated_job_id !== null) {
                    throw ValidationException::withMessages(['content' => 'Generated media is read-only. Download the original instead.']);
                }
                $filename = $this->filename($data['filename'] ?? $previous->filename);
                $mime = $this->textMime($artifact->kind, $filename);
                $this->validateText($data['content'], $artifact->kind, $mime);
                $this->quota->assertCanStore($user, strlen($data['content']));
                $revision = $this->storeText($artifact, $artifact->version + 1, $filename, $mime, $data['content'], $storedPath);
                $artifact->update(['version' => $revision->version, 'current_revision_id' => $revision->id]);

                return $this->present($user, $artifact, $revision);
            });
        } catch (Throwable $error) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $error;
        }
    }

    public function download(User $user, string $id, ?string $revisionId = null, bool $preview = false): StreamedResponse
    {
        $artifact = $this->owned($user, $id);
        $revision = $this->revision($artifact, $revisionId);
        if ($revision->generated_job_id !== null) {
            $output = $this->media->resolveOwnedOutput($user, $revision->generated_job_id, $revision->generated_output_id);
            abort_unless(($output['conversation_id'] ?? null) === $artifact->conversation_id, 404);
            $disk = $output['disk'];
            $path = $output['path'];
            // Active formats are never delivered inline on the authenticated application origin.
            $inline = $preview && ($output['previewable'] ?? false) && $this->passiveMime($revision->mime);
        } else {
            $disk = $revision->storage_disk;
            $path = $revision->storage_path;
            $inline = false;
        }
        abort_if($preview && ! $inline, 422, 'This file is available as a download only.');
        abort_unless($path && Storage::disk($disk)->exists($path), 410, 'This artifact is no longer retained.');

        // The latest revision keeps its exact name; an older one says which version it is, so two downloads never collide.
        $name = $revision->version === $artifact->version ? $revision->filename
            : preg_replace('/(\.[^.]+)?$/', '-v'.$revision->version.'$1', $revision->filename, 1);

        return Storage::disk($disk)->response($path, $name, [
            'Content-Type' => $revision->mime.($revision->generated_job_id === null ? '; charset=UTF-8' : ''),
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer',
        ], $inline ? 'inline' : 'attachment');
    }

    /** Conversation deletion owns artifact removal; generated output bytes remain with their original job. */
    public function deleteForConversation(User $user, string $key): void
    {
        DB::transaction(function () use ($user, $key) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $artifacts = ChatArtifact::query()->where('user_id', $user->id)->where('conversation_id', $key)
                ->lockForUpdate()->get();
            $paths = ChatArtifactRevision::query()->whereIn('artifact_id', $artifacts->modelKeys())
                ->whereNotNull('storage_path')->get(['storage_disk', 'storage_path']);
            ChatArtifact::query()->whereIn('id', $artifacts->modelKeys())->delete();
            DB::afterCommit(function () use ($paths) {
                foreach ($paths as $revision) {
                    Storage::disk($revision->storage_disk)->delete($revision->storage_path);
                }
            });
        });
    }

    private function owned(User $user, string $id, bool $lock = false): ChatArtifact
    {
        abort_unless($user->hasPermission('chat_history'), 403);
        abort_unless(Str::isUuid($id), 404);
        $query = ChatArtifact::query()->where('user_id', $user->id)->whereKey($id);
        $artifact = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        $this->workspaces->resolveConversation($user, $artifact->conversation_id);

        return $artifact;
    }

    private function revision(ChatArtifact $artifact, ?string $revisionId = null): ChatArtifactRevision
    {
        abort_unless($revisionId === null || Str::isUuid($revisionId), 404);
        $revision = $artifact->revisions()->whereKey($revisionId ?? $artifact->current_revision_id)->first();
        abort_unless($revision, $revisionId === null ? 410 : 404);

        return $revision;
    }

    private function provenance(User $user, string $key, array $data): array
    {
        $messageId = $data['source_message_id'] ?? null;
        $operationId = $data['source_operation_id'] ?? null;
        if ($messageId !== null) {
            $message = DB::table('chat_history')->where('user_id', $user->id)
                ->where('conversation_id', $key)->where('id', $messageId)->first();
            abort_unless($message, 404);
            if ($operationId !== null && ($message->operation_id ?? null) !== $operationId) {
                abort(404);
            }
            $operationId ??= $message->operation_id ?? null;
        }
        if ($operationId !== null) {
            abort_unless(DB::table('chat_operations')->where('user_id', $user->id)
                ->where('conversation_id', $key)->where('id', $operationId)->exists(), 404);
        }

        return [$messageId, $operationId];
    }

    private function storeText(ChatArtifact $artifact, int $version, string $filename, string $mime, string $content, ?string &$storedPath): ChatArtifactRevision
    {
        $revisionId = (string) Str::uuid();
        $storedPath = 'chat-artifacts/'.$artifact->user_id.'/'.$artifact->id.'/'.$revisionId;
        abort_unless(Storage::disk('local')->put($storedPath, $content, ['visibility' => 'private']), 503, 'The artifact could not be stored. Your draft has not been saved.');
        $revision = new ChatArtifactRevision([
            'artifact_id' => $artifact->id, 'user_id' => $artifact->user_id, 'version' => $version,
            'filename' => $filename, 'mime' => $mime, 'storage_disk' => 'local', 'storage_path' => $storedPath,
            'size_bytes' => strlen($content), 'content_bytes' => strlen($content), 'created_at' => now(),
        ]);
        $revision->id = $revisionId;
        $revision->save();

        return $revision;
    }

    private function summary(ChatArtifact $artifact, ChatArtifactRevision $revision): array
    {
        return [
            'id' => $artifact->id, 'conversation_id' => $artifact->conversation_id,
            'title' => $artifact->title, 'kind' => $artifact->kind, 'filename' => $revision->filename,
            'mime' => $revision->mime, 'version' => $revision->version, 'latest_version' => $artifact->version,
            'revision_id' => $revision->id, 'editable' => $revision->generated_job_id === null,
            'bytes' => $revision->content_bytes, 'source_message_id' => $artifact->source_message_id,
            'source_operation_id' => $artifact->source_operation_id,
            'download_url' => '/api/c/artifacts/'.$artifact->id.'/download?revision='.$revision->id,
            'created_at' => $artifact->created_at->toISOString(), 'updated_at' => $artifact->updated_at->toISOString(),
        ];
    }

    private function present(User $user, ChatArtifact $artifact, ChatArtifactRevision $revision): array
    {
        $result = $this->summary($artifact, $revision);
        $result['revisions'] = $artifact->revisions()->get(['id', 'version', 'created_at'])->map(fn ($item) => [
            'id' => $item->id, 'version' => $item->version, 'created_at' => $item->created_at->toISOString(),
        ])->all();
        if ($revision->generated_job_id === null) {
            abort_unless($revision->storage_path && Storage::disk($revision->storage_disk)->exists($revision->storage_path), 410, 'This artifact is no longer retained.');
            $result['content'] = Storage::disk($revision->storage_disk)->get($revision->storage_path);
        } else {
            $output = $this->media->resolveOwnedOutput($user, $revision->generated_job_id, $revision->generated_output_id);
            abort_unless(($output['conversation_id'] ?? null) === $artifact->conversation_id, 404);
            $previewable = ($output['previewable'] ?? false) && $this->passiveMime($revision->mime);
            $result['previewable'] = $previewable;
            $result['preview_url'] = $previewable ? '/api/c/artifacts/'.$artifact->id.'/preview?revision='.$revision->id : null;
            $result['generated_output'] = ['job_id' => $revision->generated_job_id, 'output_id' => $revision->generated_output_id];
        }

        return $result;
    }

    private function passiveMime(string $mime): bool
    {
        return in_array(strtolower($mime), [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
            'video/mp4', 'video/webm', 'video/quicktime', 'audio/mpeg', 'audio/mp4', 'audio/wav',
            'audio/x-wav', 'audio/ogg', 'audio/webm', 'audio/flac', 'model/gltf-binary',
        ], true);
    }

    private function filename(string $filename): string
    {
        $filename = trim($filename);
        if ($filename === '' || in_array($filename, ['.', '..'], true) || mb_strlen($filename) > 180
            || preg_match('/[\x00-\x1f\x7f\/\\\\]/u', $filename)) {
            throw ValidationException::withMessages(['filename' => 'Use a filename without folders or control characters (up to 180 characters).']);
        }

        return $filename;
    }

    private function inferKind(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'html', 'svg' => 'svg', 'md', 'markdown' => 'markdown', 'json' => 'json',
            'csv', 'tsv' => 'table',
            'js', 'jsx', 'ts', 'tsx', 'py', 'php', 'css', 'scss', 'java', 'c', 'cpp', 'h', 'rs', 'go', 'rb', 'sh', 'sql', 'yaml', 'yml', 'xml' => 'code',
            default => 'text',
        };
    }

    private function textMime(string $kind, string $filename, ?string $requested = null): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = match ($kind) {
            'html' => 'text/html', 'svg' => 'image/svg+xml', 'markdown' => 'text/markdown', 'json' => 'application/json',
            'table' => match ($extension) { 'tsv' => 'text/tab-separated-values', 'json' => 'application/json', default => 'text/csv' },
            'text' => 'text/plain',
            'code' => match ($extension) {
                'js', 'jsx' => 'text/javascript', 'ts', 'tsx' => 'text/typescript', 'css' => 'text/css',
                'py' => 'text/x-python', 'php' => 'text/x-php', 'sh' => 'text/x-shellscript',
                'json' => 'application/json', 'xml' => 'application/xml', 'yaml', 'yml' => 'text/yaml',
                default => 'text/plain',
            },
            default => throw ValidationException::withMessages(['kind' => 'Choose a supported text artifact type, or reference an owned generated output.']),
        };
        if ($requested !== null && strtolower(trim(explode(';', $requested)[0])) !== $mime) {
            throw ValidationException::withMessages(['mime' => 'The MIME type does not match the selected artifact format.']);
        }

        return $mime;
    }

    private function validateText(string $content, string $kind, string $mime): void
    {
        if (strlen($content) > self::MAX_TEXT_BYTES || ! mb_check_encoding($content, 'UTF-8')
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $content)) {
            throw ValidationException::withMessages(['content' => 'Text artifacts must be UTF-8 text of at most 2 MiB. Save binary media by its generated output reference.']);
        }
        if ($mime === 'application/json') {
            try {
                $parsed = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw ValidationException::withMessages(['content' => 'Enter valid JSON before saving.']);
            }
            if ($kind === 'table' && (! is_array($parsed) || ! array_is_list($parsed))) {
                throw ValidationException::withMessages(['content' => 'A JSON table must be an array of rows.']);
            }
        }
    }
}
