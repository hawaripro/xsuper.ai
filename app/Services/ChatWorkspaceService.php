<?php

namespace App\Services;

use App\Media\AssetService;
use App\Media\Enums\InputRole;
use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatOperation;
use App\Models\ChatWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class ChatWorkspaceService
{
    private const SORT_TIME = 'COALESCE(history_summary.last_message_at, chat_conversations.created_at)';

    public function __construct(private readonly AssetService $assets) {}

    public function workspaces(User $user): array
    {
        $default = $this->defaultWorkspace($user);
        $workspaces = ChatWorkspace::query()->where('user_id', $user->id)
            ->orderByRaw('CASE WHEN default_key IS NULL THEN 1 ELSE 0 END')->orderBy('id')->get();

        return [
            'workspaces' => $workspaces->map(fn (ChatWorkspace $workspace): array => $this->presentWorkspace($workspace))->all(),
            'workspace' => $this->presentWorkspace($default),
        ];
    }

    public function defaultWorkspace(User $user): ChatWorkspace
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user): ChatWorkspace {
            $this->lockOwner($user);

            return $this->personalWorkspace($user);
        });
    }

    public function createWorkspace(User $user, string $name): ChatWorkspace
    {
        $this->authorize($user);
        $validated = Validator::make(['name' => trim($name)], ['name' => ['required', 'string', 'max:100']])->validate();

        return ChatWorkspace::create(['user_id' => $user->id, 'name' => $validated['name'], 'notes' => '', 'version' => 1]);
    }

    public function updateWorkspace(User $user, int $id, array $attributes): ChatWorkspace
    {
        $this->authorize($user);
        if (array_key_exists('name', $attributes) && is_string($attributes['name'])) {
            $attributes['name'] = trim($attributes['name']);
        }
        $validated = Validator::make($attributes, [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
            // The column is a 32-bit integer; a larger number is invalid input (422), never a database error.
            'version' => ['required', 'integer', 'min:1', 'max:2147483647'],
        ])->validate();
        return DB::transaction(function () use ($user, $id, $validated): ChatWorkspace {
            $this->ownedWorkspace($user, $id);
            $changes = ['version' => DB::raw('version + 1'), 'updated_at' => now()];
            foreach (['name', 'notes'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $changes[$field] = $validated[$field] ?? '';
                }
            }
            if (count($changes) === 2) {
                throw ValidationException::withMessages(['workspace' => 'Kirim nama atau catatan yang ingin disimpan.']);
            }
            $updated = ChatWorkspace::query()->where('user_id', $user->id)->whereKey($id)
                ->where('version', $validated['version'])->update($changes);
            abort_unless($updated === 1, 409, 'Workspace sudah diubah. Muat versi terbaru sebelum menyimpan kembali.');

            return $this->ownedWorkspace($user, $id);
        });
    }

    public function presentWorkspace(ChatWorkspace $workspace): array
    {
        return ['id' => $workspace->id, 'name' => $workspace->name, 'notes' => $workspace->notes ?? '', 'version' => $workspace->version];
    }

    public function resolveConversation(User $user, string $key, bool $create = false, ?int $workspaceId = null): ChatConversation
    {
        $this->authorize($user);
        $this->validateKey($key);

        return DB::transaction(function () use ($user, $key, $create, $workspaceId): ChatConversation {
            $this->lockOwner($user);
            $requestedWorkspace = $workspaceId !== null ? $this->ownedWorkspace($user, $workspaceId) : null;
            $conversation = ChatConversation::query()->where('user_id', $user->id)->where('conversation_key', $key)
                ->lockForUpdate()->first();
            if ($conversation !== null) {
                abort_if($conversation->deleted_at !== null, 410, 'Percakapan ini telah dihapus. Buat percakapan baru.');
                if ($conversation->workspace_id === null) {
                    $conversation->update(['workspace_id' => $this->personalWorkspace($user)->id]);
                }
            } else {
                $legacy = DB::table('chat_history')->where('user_id', $user->id)->where('conversation_id', $key)
                    ->selectRaw('COUNT(*) as message_count, MIN(created_at) as started_at, MAX(created_at) as last_message_at')->first();
                $hasHistory = (int) $legacy->message_count > 0;
                abort_unless($create || $hasHistory, 404, 'Percakapan tidak ditemukan.');
                $workspace = $hasHistory ? $this->personalWorkspace($user) : ($requestedWorkspace ?? $this->personalWorkspace($user));
                $conversation = new ChatConversation([
                    'user_id' => $user->id, 'workspace_id' => $workspace->id, 'conversation_key' => $key,
                    'title' => 'New Chat', 'pinned' => false, 'title_is_custom' => false,
                ]);
                $conversation->forceFill([
                    'created_at' => $legacy->started_at ?? now(), 'updated_at' => $legacy->last_message_at ?? now(),
                ])->save();
            }
            abort_if($requestedWorkspace !== null && $conversation->workspace_id !== $requestedWorkspace->id,
                409, 'Percakapan berada di workspace lain. Muat ulang sebelum melanjutkan.');

            return $conversation->load('workspace');
        });
    }

    public function createConversation(User $user, array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'conversation_id' => ['required', 'string', 'max:100'],
            'workspace_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($user, $validated): array {
            $conversation = $this->resolveConversation($user, $validated['conversation_id'], true, $validated['workspace_id'] ?? null);
            if (! $user->hasPermission('chat_history') && DB::table('chat_history')
                ->where('user_id', $user->id)->where('conversation_id', $conversation->conversation_key)->exists()) {
                abort(403, 'Anda tidak memiliki akses ke riwayat percakapan.');
            }
            if (array_key_exists('title', $validated)) {
                $title = trim($validated['title']);
                if ($title === '') {
                    throw ValidationException::withMessages(['title' => 'Judul percakapan tidak boleh kosong.']);
                }
                // Repeated create must not rename an existing conversation.
                if ($conversation->wasRecentlyCreated && ! DB::table('chat_history')
                    ->where('user_id', $user->id)->where('conversation_id', $conversation->conversation_key)->exists()) {
                    $conversation->update(['title' => $title, 'title_is_custom' => true]);
                }
            }

            return $this->presentConversation($this->conversationQuery($user)->whereKey($conversation->id)->firstOrFail());
        });
    }

    public function updateConversation(User $user, string $key, array $attributes): array
    {
        $this->authorize($user, true);
        if (array_key_exists('title', $attributes) && is_string($attributes['title'])) {
            $attributes['title'] = trim($attributes['title']);
        }
        $validated = Validator::make($attributes, [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'pinned' => ['sometimes', 'boolean'],
            'workspace_id' => ['sometimes', 'required', 'integer', 'min:1'],
        ])->validate();
        if ($validated === []) {
            throw ValidationException::withMessages(['conversation' => 'Tidak ada perubahan percakapan untuk disimpan.']);
        }

        return DB::transaction(function () use ($user, $key, $validated): array {
            $this->lockOwner($user);
            $conversation = $this->resolveConversation($user, $key);
            if (isset($validated['workspace_id'])) {
                $workspace = $this->ownedWorkspace($user, (int) $validated['workspace_id']);
                $validated['workspace_id'] = $workspace->id;
                ChatAttachment::query()->where('user_id', $user->id)->where('conversation_id', $conversation->id)
                    ->update(['workspace_id' => $workspace->id]);
            }
            if (isset($validated['title'])) {
                $validated['title_is_custom'] = true;
            }
            $conversation->update($validated);

            return $this->presentConversation($this->conversationQuery($user)->whereKey($conversation->id)->firstOrFail());
        });
    }

    public function history(User $user, array $filters = []): array
    {
        $this->authorize($user, true);
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'], 'workspace_id' => ['nullable', 'integer', 'min:1'],
            'cursor' => ['nullable', 'string', 'max:2048'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ])->validate();
        $queryText = trim($validated['q'] ?? '');
        $workspaceId = isset($validated['workspace_id']) ? (int) $validated['workspace_id'] : null;
        if ($workspaceId !== null) {
            $this->ownedWorkspace($user, $workspaceId);
        }
        $this->backfillHistory($user);
        $query = $this->conversationQuery($user);
        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId);
        }
        if ($queryText !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($queryText)).'%';
            $query->where(function (Builder $matching) use ($pattern): void {
                $matching->where(function (Builder $title) use ($pattern): void {
                    $title->where(fn (Builder $q) => $q->where('title_is_custom', true)->orWhereNull('history_summary.message_count'))
                        ->whereRaw("LOWER(chat_conversations.title) LIKE ? ESCAPE '!'", [$pattern]);
                })->orWhereExists(function ($messages) use ($pattern): void {
                    $messages->selectRaw('1')->from('chat_history as matching_messages')
                        ->whereColumn('matching_messages.user_id', 'chat_conversations.user_id')
                        ->whereColumn('matching_messages.conversation_id', 'chat_conversations.conversation_key')
                        ->where(function ($content) use ($pattern): void {
                            $content->whereRaw("LOWER(matching_messages.content) LIKE ? ESCAPE '!'", [$pattern])
                                ->orWhereRaw("LOWER(matching_messages.model) LIKE ? ESCAPE '!'", [$pattern]);
                        });
                });
            });
        }
        $filterHash = hash('sha256', json_encode([$user->id, $workspaceId, $queryText], JSON_THROW_ON_ERROR));
        if (! empty($validated['cursor'])) {
            $cursor = $this->decodeCursor($validated['cursor'], $filterHash);
            $query->where(function (Builder $after) use ($cursor): void {
                $after->where('pinned', '<', (bool) $cursor['p'])
                    ->orWhere(function (Builder $samePin) use ($cursor): void {
                        $samePin->where('pinned', (bool) $cursor['p'])->whereRaw(self::SORT_TIME.' < ?', [$cursor['t']]);
                    })->orWhere(function (Builder $sameTime) use ($cursor): void {
                        $sameTime->where('pinned', (bool) $cursor['p'])->whereRaw(self::SORT_TIME.' = ?', [$cursor['t']])
                            ->where('chat_conversations.id', '<', $cursor['id']);
                    });
            });
        }
        $limit = (int) ($validated['limit'] ?? 30);
        $rows = $query->orderByDesc('pinned')->orderByRaw(self::SORT_TIME.' DESC')
            ->orderByDesc('chat_conversations.id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);
        $last = $page->last();

        return [
            'conversations' => $page->map(fn (ChatConversation $row): array => $this->presentConversation($row))->values()->all(),
            'next_cursor' => $hasMore && $last !== null ? rtrim(strtr(base64_encode(json_encode([
                'p' => (int) $last->pinned, 't' => $last->sort_at, 'id' => $last->id, 'filter' => $filterHash,
            ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=') : null,
        ];
    }

    public function conversation(User $user, string $key): array
    {
        $this->authorize($user, true);
        $conversation = $this->resolveConversation($user, $key);
        $messages = DB::table('chat_history')->where('user_id', $user->id)->where('conversation_id', $key)
            ->orderBy('created_at')->orderBy('id')->get();
        $ids = $messages->flatMap(fn ($message): array => $this->messageAttachmentIds($message))->unique()->values()->all();
        $attachments = ChatAttachment::query()->where('user_id', $user->id)->where('conversation_id', $conversation->id)
            ->where('workspace_id', $conversation->workspace_id)->whereIn('id', $ids)->with('asset')->get()->keyBy('id');
        // An answer produced by retry or continuation says which saved answer it follows.
        $relations = ChatOperation::query()->where('user_id', $user->id)->where('conversation_id', $key)
            ->where(fn (Builder $query) => $query->whereNotNull('retry_of')->orWhereNotNull('continuation_of'))
            ->get(['assistant_message_id', 'retry_of', 'continuation_of'])->keyBy('assistant_message_id');

        return [
            'conversation' => $this->presentConversation($this->conversationQuery($user)->whereKey($conversation->id)->firstOrFail()),
            'messages' => $messages->map(function ($message) use ($attachments, $key, $relations): array {
                return [
                    'id' => $message->id, 'role' => $message->role, 'content' => $message->content,
                    'model' => $message->model, 'created_at' => $this->timestamp($message->created_at),
                    'status' => $message->status ?? 'completed', 'operation_id' => $message->operation_id ?? null,
                    'retry_of' => $relations->get($message->id)?->retry_of, 'continuation_of' => $relations->get($message->id)?->continuation_of,
                    'attachments' => array_map(fn (string $id): array => isset($attachments[$id])
                        ? $this->presentAttachment($attachments[$id], $key)
                        : ['id' => $id, 'status' => 'error', 'error' => 'Lampiran tidak lagi tersedia.'], $this->messageAttachmentIds($message)),
                ];
            })->all(),
        ];
    }

    public function deleteConversation(User $user, string $key): void
    {
        $this->authorize($user, true);
        $this->validateKey($key);
        DB::transaction(function () use ($user, $key): void {
            $this->lockOwner($user);
            $conversation = ChatConversation::query()->where('user_id', $user->id)->where('conversation_key', $key)
                ->lockForUpdate()->first();
            if ($conversation?->deleted_at !== null) {
                return;
            }
            $conversation ??= $this->resolveConversation($user, $key);
            app(ChatOperationService::class)->stopForDeletion($user, $key);
            $conversation->update([
                'deleted_at' => now(), 'title' => '', 'model' => null, 'pinned' => false, 'title_is_custom' => false,
            ]);
            // Preserve request/usage identities, not deleted conversation text or context.
            DB::table('chat_operations')->where('user_id', $user->id)->where('conversation_id', $key)
                ->update([
                    'partial_content' => '', 'context_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
                    'error' => 'Conversation deleted.', 'updated_at' => now(),
                ]);
            app(ChatArtifactService::class)->deleteForConversation($user, $key);
            ChatAttachment::query()->where('user_id', $user->id)->where('conversation_id', $conversation->id)
                ->whereNull('detached_at')->update(['detached_at' => now()]);
            DB::table('chat_history')->where('user_id', $user->id)->where('conversation_id', $key)->delete();
        });
    }

    /** @return Collection<int, ChatAttachment> */
    public function attachments(User $user, string $key, array $attachmentIds): Collection
    {
        $conversation = $this->resolveConversation($user, $key);
        Validator::make(['attachment_ids' => $attachmentIds], [
            'attachment_ids' => ['present', 'array', 'list', 'max:20'],
            'attachment_ids.*' => ['required', 'string', 'uuid', 'distinct'],
        ])->validate();
        $rows = ChatAttachment::query()->where('user_id', $user->id)->where('conversation_id', $conversation->id)
            ->where('workspace_id', $conversation->workspace_id)->whereNull('detached_at')->whereIn('id', $attachmentIds)
            ->with('asset')->get()->keyBy('id');
        $ordered = [];
        foreach ($attachmentIds as $id) {
            $attachment = $rows->get($id);
            if ($attachment === null || ! $this->attachmentReady($attachment)) {
                throw ValidationException::withMessages(['attachment_ids' => 'Lampiran tidak tersedia, kedaluwarsa, atau bukan milik percakapan ini.']);
            }
            $ordered[] = $attachment;
        }

        return new Collection($ordered);
    }

    public function listAttachments(User $user, string $key): array
    {
        $conversation = $this->resolveConversation($user, $key);
        $rows = ChatAttachment::query()->where('user_id', $user->id)->where('conversation_id', $conversation->id)
            ->where('workspace_id', $conversation->workspace_id)->whereNull('detached_at')->with('asset')->orderBy('created_at')->orderBy('id')->get();
        $presented = $rows->map(fn (ChatAttachment $row): array => $this->presentAttachment($row, $key));
        $policy = $this->assets->policy();

        return [
            'attachments' => $presented->where('status', 'ready')->values()->all(),
            'unavailable_attachments' => $presented->where('status', 'error')->values()->all(),
            'upload_policy' => ['max_files' => min(20, (int) $policy['max_files']), 'roles' => array_intersect_key($policy['roles'], array_flip(['image_ref', 'document']))],
        ];
    }

    public function storeAttachment(User $user, string $key, UploadedFile $file): ChatAttachment
    {
        $this->authorize($user);
        $stored = null;
        try {
            return DB::transaction(function () use ($user, $key, $file, &$stored): ChatAttachment {
                $this->lockOwner($user);
                $conversation = $this->resolveConversation($user, $key);
                $count = ChatAttachment::query()->where('user_id', $user->id)->where('conversation_id', $conversation->id)
                    ->whereNull('detached_at')->count();
                if ($count >= 20) {
                    throw ValidationException::withMessages(['file' => 'Maksimal 20 lampiran per percakapan. Lepaskan lampiran sebelum mengunggah lagi.']);
                }
                $mime = $file->isValid() ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) : '';
                // A named extension is the member's declared type: image bytes behind ".txt" are a disguised file,
                // not an image to send (a pasted image without a name has nothing to contradict).
                $extension = strtolower($file->getClientOriginalExtension());
                $imageExtensions = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
                if (isset($imageExtensions[$mime]) && $extension !== '' && ! in_array($extension, $imageExtensions[$mime], true)) {
                    throw ValidationException::withMessages(['file' => 'The attached bytes do not match their declared file type.']);
                }
                $role = isset($imageExtensions[$mime]) ? InputRole::ImageRef : InputRole::Document;
                $stored = $this->assets->store($user, $file, $role);
                $attachment = ChatAttachment::create([
                    'user_id' => $user->id, 'workspace_id' => $conversation->workspace_id, 'conversation_id' => $conversation->id,
                    'asset_id' => $stored->id, 'name' => $stored->original_name ?: 'attachment',
                ]);

                return $attachment->setRelation('asset', $stored);
            });
        } catch (Throwable $exception) {
            if ($stored !== null) {
                try {
                    Storage::disk($stored->storage_disk)->delete($stored->storage_path);
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }
            throw $exception;
        }
    }

    public function detachAttachment(User $user, string $key, string $id): void
    {
        $this->authorize($user);
        abort_unless(Str::isUuid($id), 404, 'Lampiran tidak ditemukan.');
        DB::transaction(function () use ($user, $key, $id): void {
            $this->lockOwner($user);
            $conversation = $this->resolveConversation($user, $key);
            $attachment = ChatAttachment::query()->where('user_id', $user->id)->where('conversation_id', $conversation->id)
                ->where('workspace_id', $conversation->workspace_id)->whereKey($id)->lockForUpdate()->first();
            abort_if($attachment === null, 404, 'Lampiran tidak ditemukan.');
            if ($attachment->detached_at === null) {
                $attachment->update(['detached_at' => now()]);
            }
            // The shared asset remains subject to quota/retention and operation references.
        });
    }

    public function ownedAttachment(User $user, string $key, string $id): ChatAttachment
    {
        abort_unless(Str::isUuid($id), 404, 'Lampiran tidak ditemukan.');
        $conversation = $this->resolveConversation($user, $key);
        $attachment = ChatAttachment::query()->where('user_id', $user->id)->where('conversation_id', $conversation->id)
            ->where('workspace_id', $conversation->workspace_id)->whereKey($id)->with('asset')->first();
        abort_if($attachment === null, 404, 'Lampiran tidak ditemukan.');
        if ($attachment->detached_at !== null) {
            $this->authorize($user, true);
            abort_unless(DB::table('chat_history')->where('user_id', $user->id)->where('conversation_id', $key)
                ->whereJsonContains('attachment_ids', $id)->exists(), 404, 'Lampiran telah dilepas dari percakapan.');
        }
        abort_unless($this->attachmentReady($attachment), 410, 'Berkas lampiran tidak lagi tersedia.');

        return $attachment;
    }

    public function presentAttachment(ChatAttachment $attachment, string $key): array
    {
        $asset = $attachment->asset;
        $result = [
            'id' => $attachment->id, 'asset_id' => $attachment->asset_id, 'name' => $attachment->name,
            'size' => $asset?->size_bytes, 'mime' => $asset?->mime, 'kind' => $asset?->media_type,
        ];
        if (! $this->attachmentReady($attachment)) {
            return $result + ['status' => 'error', 'error' => 'Berkas kedaluwarsa, dihapus, atau tidak lagi tersedia.'];
        }
        $base = '/api/c/h/'.rawurlencode($key).'/attachments/'.rawurlencode($attachment->id);
        $result += ['status' => 'ready', 'download_url' => $base.'/download'];
        if ($this->previewMime($attachment) !== null) {
            $result['preview_url'] = $base.'/preview';
        }

        return $result;
    }

    public function previewMime(ChatAttachment $attachment): ?string
    {
        $mime = $attachment->asset?->mime;
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            return $mime;
        }
        if (in_array($mime, ['text/plain', 'text/markdown', 'text/csv', 'application/json', 'text/json', 'application/csv'], true)) {
            return 'text/plain; charset=UTF-8';
        }

        return null;
    }

    private function authorize(User $user, bool $history = false): void
    {
        // Same activity rule as EnsureActive/CheckExpiry: only an explicit false is inactive.
        abort_unless($user->isAdmin() || ($user->is_active !== false && ! $user->isExpired()), 403, 'Akun tidak memiliki akses aktif.');
        abort_unless($user->hasPermission('chat') && (! $history || $user->hasPermission('chat_history')), 403, 'Anda tidak memiliki akses ke fitur ini.');
    }

    private function lockOwner(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    private function ownedWorkspace(User $user, int $id): ChatWorkspace
    {
        $workspace = ChatWorkspace::query()->where('user_id', $user->id)->whereKey($id)->first();
        abort_if($workspace === null, 404, 'Workspace tidak ditemukan.');

        return $workspace;
    }

    /** Caller holds the owner lock, serializing default creation and legacy backfill. */
    private function personalWorkspace(User $user): ChatWorkspace
    {
        return ChatWorkspace::firstOrCreate(['user_id' => $user->id, 'default_key' => 'personal'], ['name' => 'Personal', 'notes' => '', 'version' => 1]);
    }

    private function backfillHistory(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->lockOwner($user);
            $workspace = $this->personalWorkspace($user);
            DB::table('chat_conversations')->insertUsing(
                ['user_id', 'workspace_id', 'conversation_key', 'title', 'model', 'created_at', 'updated_at'],
                DB::table('chat_history')->where('user_id', $user->id)->where('conversation_id', '<>', '')
                    ->whereNotExists(function ($metadata): void {
                        $metadata->selectRaw('1')->from('chat_conversations')
                            ->whereColumn('chat_conversations.user_id', 'chat_history.user_id')
                            ->whereColumn('chat_conversations.conversation_key', 'chat_history.conversation_id');
                    })->select('user_id')->selectRaw('? as workspace_id', [$workspace->id])->addSelect('conversation_id')
                    ->selectRaw('? as title, NULL as model', ['New Chat'])
                    ->selectRaw('MIN(created_at) as created_at, MAX(created_at) as updated_at')->groupBy('user_id', 'conversation_id'),
            );
        });
    }

    /** @return Builder<ChatConversation> */
    private function conversationQuery(User $user): Builder
    {
        $history = DB::table('chat_history')->where('user_id', $user->id)->select('conversation_id')
            ->selectRaw('MIN(created_at) as started_at, MAX(created_at) as last_message_at, COUNT(*) as message_count')
            ->groupBy('conversation_id');
        $firstMessage = DB::table('chat_history as first_message')->selectRaw('SUBSTR(first_message.content, 1, 160)')
            ->whereColumn('first_message.user_id', 'chat_conversations.user_id')
            ->whereColumn('first_message.conversation_id', 'chat_conversations.conversation_key')->where('first_message.role', 'user')
            ->orderBy('first_message.created_at')->orderBy('first_message.id')->limit(1);
        $latestModel = DB::table('chat_history as latest_message')->select('model')
            ->whereColumn('latest_message.user_id', 'chat_conversations.user_id')
            ->whereColumn('latest_message.conversation_id', 'chat_conversations.conversation_key')->whereNotNull('model')
            ->orderByDesc('latest_message.created_at')->orderByDesc('latest_message.id')->limit(1);

        return ChatConversation::query()->where('chat_conversations.user_id', $user->id)
            ->whereNotNull('chat_conversations.conversation_key')->whereNull('chat_conversations.deleted_at')
            ->leftJoinSub($history, 'history_summary', fn ($join) => $join->on('history_summary.conversation_id', '=', 'chat_conversations.conversation_key'))
            ->select('chat_conversations.*')->addSelect(['history_summary.started_at', 'history_summary.last_message_at', 'history_summary.message_count'])
            ->selectRaw(self::SORT_TIME.' as sort_at')->selectSub($firstMessage, 'first_user_content')->selectSub($latestModel, 'latest_model');
    }

    private function presentConversation(ChatConversation $conversation): array
    {
        $title = $conversation->title_is_custom ? $conversation->title : trim((string) $conversation->first_user_content);
        $startedAt = $this->timestamp($conversation->started_at ?? $conversation->created_at);
        $lastAt = $this->timestamp($conversation->last_message_at ?? $conversation->created_at);

        return [
            'conversation_id' => $conversation->conversation_key,
            // The stored "New Chat" is a placeholder, not a name: untitled stays empty so the UI names it in its locale.
            'title' => $conversation->title_is_custom ? $title : Str::limit($title, 70),
            'model' => $conversation->latest_model ?? $conversation->model,
            'created_at' => $startedAt, 'last_message_at' => $lastAt,
            'workspace_id' => $conversation->workspace_id, 'pinned' => $conversation->pinned,
            'started_at' => $startedAt, 'last_message' => $lastAt, 'message_count' => (int) ($conversation->message_count ?? 0),
        ];
    }

    private function decodeCursor(string $encoded, string $filterHash): array
    {
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
        $cursor = $raw !== false ? json_decode($raw, true) : null;
        if (! is_array($cursor) || ! in_array($cursor['p'] ?? null, [0, 1], true)
            || ! is_int($cursor['id'] ?? null) || $cursor['id'] < 1
            || ! is_string($cursor['t'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?$/', $cursor['t'])
            || Validator::make(['time' => $cursor['t']], ['time' => ['required', 'date']])->fails()
            || ($cursor['filter'] ?? null) !== $filterHash) {
            throw ValidationException::withMessages(['cursor' => 'Halaman riwayat tidak valid untuk pencarian ini. Mulai pencarian kembali.']);
        }

        return $cursor;
    }

    private function attachmentReady(ChatAttachment $attachment): bool
    {
        $asset = $attachment->asset;

        if ($asset === null || (int) $asset->user_id !== $attachment->user_id) {
            return false;
        }
        try {
            $this->assets->assertUsable($asset);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private function messageAttachmentIds(object $message): array
    {
        $ids = $message->attachment_ids ?? [];
        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    private function validateKey(string $key): void
    {
        Validator::make(['conversation_id' => $key], ['conversation_id' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/']])->validate();
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
