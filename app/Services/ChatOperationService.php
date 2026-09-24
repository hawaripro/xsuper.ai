<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatOperation;
use App\Models\MediaAsset;
use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatOperationService
{
    public function __construct(
        private readonly AiProxyService $proxy,
        private readonly ChatWorkspaceService $workspaces,
        private readonly ChatCapabilityService $capabilities,
    ) {}

    /** Admission is the only place that inserts messages or permits a provider attempt. */
    public function admit(User $user, array $input): array
    {
        abort_unless($user->hasPermission('chat') && ($user->isAdmin() || ($user->is_active !== false && ! $user->isExpired())), 403, 'Chat access is unavailable.');
        $requestId = $input['client_request_id'] ?? (string) Str::uuid();
        $conversationKey = $input['conversation_id'] ?? 'request-'.$requestId;
        $selectedTools = $input['tools'] ?? [];
        ksort($selectedTools);
        $intent = [
            'model' => $input['model'], 'conversation_id' => $conversationKey,
            'messages' => $input['messages'], 'attachment_ids' => array_values($input['attachment_ids'] ?? []),
            'tools' => $selectedTools, 'workspace_id' => isset($input['workspace_id']) ? (int) $input['workspace_id'] : null,
            'retry_of' => isset($input['retry_of']) ? (int) $input['retry_of'] : null,
            'continuation_of' => isset($input['continuation_of']) ? (int) $input['continuation_of'] : null,
            'continuation' => (bool) ($input['continuation'] ?? false),
            'owned_files_only' => ($input['stream_protocol'] ?? null) === 'workspace_v2',
        ];
        $fingerprint = hash('sha256', json_encode($intent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $requestId, $conversationKey, $intent, $fingerprint): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = ChatOperation::query()->where('user_id', $user->id)->where('client_request_id', $requestId)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'This request key is already used for different input.');
                $conversation = $this->lockedConversation($existing);
                abort_if(! $conversation || $conversation->deleted_at, 410, 'This conversation was deleted.');
                $this->expireLocked($existing);

                return [$existing, false];
            }

            $resolved = $this->capabilities->resolve($user, $intent['model']);
            $metadata = $resolved['metadata'];
            if (! $metadata['streaming']['can_stop']) {
                throw ValidationException::withMessages(['model' => $metadata['streaming']['stop_reason']]);
            }
            $profile = $resolved['profile'];
            $tools = $this->capabilities->tools($intent['tools'], $metadata);
            $submittedMessages = $this->capabilities->messages($intent['messages'], $metadata, $intent['owned_files_only']);
            if (! $tools['file_analysis']) {
                foreach ($submittedMessages as $message) {
                    foreach (is_array($message['content']) ? $message['content'] : [] as $part) {
                        if (($part['type'] ?? null) !== 'text') {
                            throw ValidationException::withMessages(['tools.file_analysis' => 'Enable file analysis to send attached content.']);
                        }
                    }
                }
            }
            $conversation = $this->workspaces->resolveConversation($user, $conversationKey, true, $intent['workspace_id']);
            $conversation = ChatConversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            abort_if($conversation->deleted_at, 410, 'This conversation was deleted.');
            if ($intent['workspace_id'] !== null && (int) $conversation->workspace_id !== $intent['workspace_id']) {
                throw ValidationException::withMessages(['workspace_id' => 'This conversation belongs to another workspace.']);
            }
            foreach (ChatOperation::query()->where('user_id', $user->id)->where('conversation_id', $conversationKey)->whereIn('status', ChatOperation::ACTIVE)->get() as $active) {
                $this->expireLocked($active);
                abort_if($active->isActive(), 409, 'This conversation already has an active response.');
            }

            $targetId = $intent['retry_of'] ?? $intent['continuation_of'];
            if ($intent['retry_of'] && ($intent['continuation_of'] || $intent['continuation'])) {
                throw ValidationException::withMessages(['retry_of' => 'Choose retry or continuation, not both.']);
            }
            if ($intent['owned_files_only'] && $intent['continuation'] && ! $intent['continuation_of']) {
                throw ValidationException::withMessages(['continuation_of' => 'Choose the specific assistant message to continue.']);
            }
            if (! $targetId && $intent['continuation']) {
                $targetId = DB::table('chat_history')->where('user_id', $user->id)->where('conversation_id', $conversationKey)
                    ->where('role', 'assistant')->orderByDesc('id')->value('id');
                $intent['continuation_of'] = $targetId;
                if (! $targetId) {
                    throw ValidationException::withMessages(['continuation_of' => 'There is no saved assistant response to continue.']);
                }
            }
            $target = null;
            $userMessage = null;
            if ($targetId) {
                $target = DB::table('chat_history')->where('user_id', $user->id)->where('conversation_id', $conversationKey)
                    ->where('role', 'assistant')->where('id', $targetId)->first();
                if (! $target || in_array($target->status, ChatOperation::ACTIVE, true)) {
                    throw ValidationException::withMessages(['retry_of' => 'The target must be a saved terminal assistant response in this conversation.']);
                }
                if ($intent['retry_of'] && ! in_array($target->status, ['stopped', 'failed'], true)) {
                    throw ValidationException::withMessages(['retry_of' => 'Only a stopped or failed attempt can be retried.']);
                }
                if ($intent['continuation_of'] && trim($target->content) === '') {
                    throw ValidationException::withMessages(['continuation_of' => 'There is no saved response content to continue.']);
                }
                // A new answer is saved after every existing turn; for an older target it would sit after later
                // questions and scramble the context of every following request.
                if (DB::table('chat_history')->where('user_id', $user->id)->where('conversation_id', $conversationKey)
                    ->whereIn('role', ['user', 'assistant'])->where('id', '>', $targetId)->exists()) {
                    throw ValidationException::withMessages([$intent['retry_of'] ? 'retry_of' : 'continuation_of' => 'Only the latest answer can be retried or continued.']);
                }
                $source = $target->operation_id ? ChatOperation::query()->where('user_id', $user->id)->whereKey($target->operation_id)->first() : null;
                $userMessage = DB::table('chat_history')->where('user_id', $user->id)->where('conversation_id', $conversationKey)->where('role', 'user')
                    ->when($source, fn ($query) => $query->where('id', $source->user_message_id), fn ($query) => $query->where('id', '<', $targetId))
                    ->orderByDesc('id')->first();
                if (! $userMessage) {
                    throw ValidationException::withMessages(['retry_of' => 'The original user turn is unavailable.']);
                }
            }
            $attachmentIds = $intent['attachment_ids'];
            if ($userMessage && $tools['file_analysis'] && $attachmentIds === []) {
                $attachmentIds = $this->decodedIds($userMessage->attachment_ids);
            }
            $attachments = $this->capabilities->attachments(
                $this->workspaces->attachments($user, $conversationKey, $attachmentIds), $metadata, $tools,
            );
            $operationId = (string) Str::uuid();
            $lastMessage = $submittedMessages[array_key_last($submittedMessages)];
            if (! $userMessage && $lastMessage['role'] !== 'user') {
                throw ValidationException::withMessages(['messages' => 'A new turn must end with a user message.']);
            }
            $userContent = $userMessage?->content ?? $this->textContent($lastMessage['content']);
            if (! $userMessage && trim($userContent) === '' && $attachments === []) {
                throw ValidationException::withMessages(['messages' => 'Enter a message or select a supported attachment.']);
            }
            if (! $userMessage) {
                $userMessageId = DB::table('chat_history')->insertGetId([
                    'user_id' => $user->id, 'conversation_id' => $conversationKey, 'role' => 'user',
                    'content' => $userContent, 'model' => $intent['model'], 'operation_id' => $operationId,
                    'status' => 'completed', 'attachment_ids' => json_encode($attachmentIds, JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
            } else {
                $userMessageId = $userMessage->id;
            }
            $assistantMessageId = DB::table('chat_history')->insertGetId([
                'user_id' => $user->id, 'conversation_id' => $conversationKey, 'role' => 'assistant',
                'content' => '', 'model' => $intent['model'], 'operation_id' => $operationId, 'status' => 'queued', 'created_at' => now(),
            ]);
            if ($intent['owned_files_only'] || $target) {
                $continuing = $intent['continuation_of'] ? (int) $target->id : null;
                $messages = $this->history($user, $conversationKey, $continuing ?? $userMessageId, $continuing);
            } else {
                $messages = $submittedMessages;
            }
            if ($intent['continuation_of']) {
                $messages[] = ['role' => 'user', 'content' => 'Continue the previous assistant answer from where it stopped, without repeating the saved content.'];
            }
            if ($intent['owned_files_only'] || $target) {
                $messages = $this->capabilities->messages($messages, $metadata, true);
            }
            $conversation->load('workspace');
            $snapshot = [
                'workspace_id' => $conversation->workspace_id,
                'notes' => $conversation->workspace?->notes ?? '', 'notes_version' => $conversation->workspace?->version,
                'tools' => $tools, 'model' => $intent['model'], 'request_messages' => $messages,
                'attachment_ids' => $attachmentIds, 'asset_ids' => array_column($attachments, 'asset_id'), 'attachments' => $attachments,
                'route' => [
                    'provider_id' => $profile->provider_id, 'upstream_model_id' => $profile->upstream_model_id ?: $profile->model_id,
                    'protocol' => $profile->provider->base_url !== null ? $profile->provider->protocol : 'openai',
                    'max_output_tokens' => $profile->max_output_tokens,
                ],
            ];
            $operation = ChatOperation::query()->create([
                'id' => $operationId, 'user_id' => $user->id, 'conversation_id' => $conversationKey,
                'client_request_id' => $requestId, 'fingerprint' => $fingerprint, 'model' => $intent['model'],
                'user_message_id' => $userMessageId, 'assistant_message_id' => $assistantMessageId,
                'retry_of' => $intent['retry_of'], 'continuation_of' => $intent['continuation_of'],
                'attachment_ids' => $attachmentIds, 'context_snapshot' => $snapshot, 'partial_content' => '',
                'status' => 'queued', 'heartbeat_at' => now(),
            ]);

            return [$operation, true];
        }, 3);
    }

    public function operation(User $user, string $id): array
    {
        $operation = $this->owned($user, $id);
        $this->expire($operation);

        return $this->present($operation->fresh());
    }

    public function stop(User $user, string $id): array
    {
        $operation = $this->owned($user, $id);
        $operation = DB::transaction(function () use ($operation): ChatOperation {
            User::query()->whereKey($operation->user_id)->lockForUpdate()->firstOrFail();
            $conversation = $this->lockedConversation($operation);
            $locked = ChatOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($locked->isActive()) {
                $locked->forceFill(['status' => 'stopped', 'stop_requested_at' => now(), 'finished_at' => now()])->save();
                if ($conversation && ! $conversation->deleted_at) {
                    $this->updateMessage($locked);
                }
                $this->recordUsage($locked);
            }

            return $locked;
        }, 3);

        return $this->present($operation);
    }

    public function stream(ChatOperation $operation, bool $workspaceProtocol, string $systemPrompt): StreamedResponse
    {
        return new StreamedResponse(function () use ($operation, $workspaceProtocol, $systemPrompt): void {
            $previousAbortSetting = ignore_user_abort(true);
            try {
                if (! $this->claim($operation)) {
                    $this->replay($operation, $workspaceProtocol);
                    return;
                }
                $operation->refresh();
                if ($workspaceProtocol) {
                    $this->event('operation', $this->eventState($operation));
                    $this->event('state', $this->eventState($operation));
                }
                $cancelled = false;
                $finished = false;
                $lastCheck = 0.0;
                $lastHeartbeat = microtime(true);
                $isCancelled = function () use ($operation, &$cancelled, &$finished, &$lastCheck, &$lastHeartbeat): bool {
                    if ($cancelled) {
                        return true;
                    }
                    // The provider already finished: a tab closing while the last bytes are written does not undo the answer.
                    if ($finished) {
                        return false;
                    }
                    $now = microtime(true);
                    if ($now - $lastCheck < 0.2 && ! $this->clientDisconnected()) {
                        return false;
                    }
                    $lastCheck = $now;
                    if ($this->clientDisconnected()) {
                        $this->finish($operation, 'stopped', null);
                        return $cancelled = true;
                    }
                    $current = ChatOperation::query()->whereKey($operation->id)->first(['status', 'stop_requested_at']);
                    $cancelled = ! $current || $current->status !== 'streaming' || $current->stop_requested_at !== null;
                    if (! $cancelled && $now - $lastHeartbeat >= 5) {
                        ChatOperation::query()->whereKey($operation->id)->where('status', 'streaming')->update(['heartbeat_at' => now()]);
                        $lastHeartbeat = $now;
                    }

                    return $cancelled;
                };
                $status = 'completed';
                $error = null;
                $partial = '';
                $usage = null;
                try {
                    $messages = $this->requestMessages($operation, $systemPrompt);
                    $route = $operation->context_snapshot['route'];
                    $options = ['_is_cancelled' => $isCancelled, '_route_snapshot' => $route];
                    $events = $this->proxy->streamChatCompletion($messages, $operation->model, $options, $route['max_output_tokens'] ?: null);
                    foreach ($events as $event) {
                        $observedUsage = $this->measuredUsage($event['usage'] ?? null);
                        if ($observedUsage !== null) {
                            $usage = $observedUsage;
                        }
                        if ($isCancelled()) {
                            $status = 'stopped';
                            break;
                        }
                        $delta = (array) ($event['choices'][0]['delta'] ?? []);
                        $text = $delta['content'] ?? $delta['refusal'] ?? null;
                        if (is_string($text)) {
                            $partial .= $text;
                        }
                        $finished = $finished || (($event['choices'][0]['finish_reason'] ?? null) !== null);
                        if (! $this->checkpoint($operation, $partial, $usage)) {
                            $status = 'stopped';
                            break;
                        }
                        $this->event(null, $event);
                    }
                    if ($status !== 'stopped' && $isCancelled()) {
                        $status = 'stopped';
                    }
                    if ($status === 'completed' && ! $finished) {
                        throw new AiProxyException('The AI provider returned an incomplete stream.', 502);
                    }
                } catch (Throwable $exception) {
                    $status = ($exception instanceof AiProxyException && $exception->responseStatus() === 499) || $isCancelled() ? 'stopped' : 'failed';
                    if ($status === 'failed') {
                        $error = $exception instanceof AiProxyException ? $exception->getMessage() : 'The response could not be completed. Your saved partial response is retained.';
                        Log::warning('Workspace chat stream failed', ['operation_id' => $operation->id, 'status' => $exception instanceof AiProxyException ? $exception->responseStatus() : 502]);
                    }
                } finally {
                    unset($events);
                }
                $final = $this->finish($operation, $status, $error, $usage);
                if ($final !== null) {
                    $this->terminal($final, $workspaceProtocol);
                }
            } catch (Throwable) {
                Log::warning('Workspace chat could not confirm its saved state', ['operation_id' => $operation->id]);
                $error = [
                    'message' => 'The saved response state could not be confirmed. Reconnect with the same request key; do not submit a new request.',
                    'type' => 'operation_error',
                ];
                $this->event($workspaceProtocol ? 'error' : null, $workspaceProtocol
                    ? [...$error, 'operation_id' => $operation->id, 'conversation_id' => $operation->conversation_id]
                    : ['error' => $error]);
            } finally {
                ignore_user_abort($previousAbortSetting);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store',
            'Connection' => 'keep-alive', 'X-Accel-Buffering' => 'no', 'X-Chat-Operation-Id' => $operation->id,
        ]);
    }

    private function claim(ChatOperation $operation): bool
    {
        return DB::transaction(function () use ($operation): bool {
            if (! User::query()->whereKey($operation->user_id)->lockForUpdate()->first()) {
                return false;
            }
            $conversation = $this->lockedConversation($operation);
            $locked = ChatOperation::query()->whereKey($operation->id)->lockForUpdate()->first();
            if (! $locked || ! $conversation || $conversation->deleted_at || $locked->status !== 'queued') {
                return false;
            }
            $locked->forceFill(['status' => 'streaming', 'started_at' => now(), 'heartbeat_at' => now()])->save();
            $this->updateMessage($locked);

            return true;
        }, 3);
    }

    private function checkpoint(ChatOperation $operation, string $partial, ?array $usage): bool
    {
        return DB::transaction(function () use ($operation, $partial, $usage): bool {
            if (! User::query()->whereKey($operation->user_id)->lockForUpdate()->first()) {
                return false;
            }
            $conversation = $this->lockedConversation($operation);
            $locked = ChatOperation::query()->whereKey($operation->id)->lockForUpdate()->first();
            if (! $locked || ! $conversation || $conversation->deleted_at || $locked->status !== 'streaming' || $locked->stop_requested_at) {
                return false;
            }
            $locked->forceFill(['partial_content' => $partial, 'usage' => $usage, 'heartbeat_at' => now()])->save();
            $this->updateMessage($locked);

            return true;
        }, 3);
    }

    private function finish(ChatOperation $operation, string $status, ?string $error, ?array $usage = null): ?ChatOperation
    {
        return DB::transaction(function () use ($operation, $status, $error, $usage): ?ChatOperation {
            if (! User::query()->whereKey($operation->user_id)->lockForUpdate()->first()) {
                return null;
            }
            $conversation = $this->lockedConversation($operation);
            $locked = ChatOperation::query()->whereKey($operation->id)->lockForUpdate()->first();
            if (! $locked) {
                return null;
            }
            if ($usage !== null && ! $locked->usage_recorded_at) {
                $locked->forceFill(['usage' => $usage])->save();
            }
            if ($locked->isActive()) {
                $stopped = ! $conversation || $conversation->deleted_at || $locked->stop_requested_at;
                $locked->forceFill([
                    'status' => $stopped ? 'stopped' : $status, 'error' => $error === null ? null : mb_substr($error, 0, 500),
                    'finished_at' => now(), 'heartbeat_at' => now(),
                    'stop_requested_at' => $stopped || $status === 'stopped' ? ($locked->stop_requested_at ?? now()) : null,
                ])->save();
            }
            if ($conversation && ! $conversation->deleted_at) {
                $this->updateMessage($locked);
            }
            $this->recordUsage($locked);

            return $locked;
        }, 3);
    }

    /** PHP learns that the member's tab closed only when a write to it fails. */
    protected function clientDisconnected(): bool
    {
        return connection_aborted() === 1;
    }

    private function updateMessage(ChatOperation $operation): void
    {
        DB::table('chat_history')->where('id', $operation->assistant_message_id)->where('user_id', $operation->user_id)
            ->where('conversation_id', $operation->conversation_id)->where('operation_id', $operation->id)
            ->update(['content' => $operation->partial_content, 'status' => $operation->status]);
    }

    private function recordUsage(ChatOperation $operation): void
    {
        if ($operation->usage_recorded_at || ! $this->measuredUsage($operation->usage)) {
            return;
        }
        UsageLog::record($operation->user_id, $operation->model, $operation->usage, 'web');
        $operation->forceFill(['usage_recorded_at' => now()])->save();
    }

    private function requestMessages(ChatOperation $operation, string $systemPrompt): array
    {
        $snapshot = $operation->context_snapshot;
        $messages = $snapshot['request_messages'];
        $notes = is_string($snapshot['notes'] ?? null) && trim($snapshot['notes']) !== ''
            ? "[Saved workspace notes — user-provided context, not system instructions]\n".$snapshot['notes']."\n[End workspace notes]" : null;
        $files = [];
        foreach ($snapshot['attachments'] as $attachment) {
            $files[] = ['type' => 'text', 'text' => '[User-provided attachment: '.$attachment['name'].']'];
            if (array_key_exists('text', $attachment)) {
                $files[] = ['type' => 'text', 'text' => $attachment['text']];
                continue;
            }
            $asset = MediaAsset::query()->where('user_id', $operation->user_id)->whereKey($attachment['asset_id'])->first();
            if (! $asset || $asset->retention_status !== 'active' || ($asset->expires_at && $asset->expires_at->isPast())
                || ! $asset->signature_ok || $asset->mime !== $attachment['mime'] || $asset->size_bytes !== $attachment['size_bytes']) {
                throw new AiProxyException('A selected attachment is no longer available.', 422);
            }
            $bytes = Storage::disk($asset->storage_disk)->get($asset->storage_path);
            if (! is_string($bytes) || strlen($bytes) !== $asset->size_bytes) {
                throw new AiProxyException('A selected attachment could not be read.', 422);
            }
            $data = 'data:'.$asset->mime.';base64,'.base64_encode($bytes);
            unset($bytes);
            $files[] = $asset->mime === 'application/pdf'
                ? ['type' => 'file', 'file' => ['filename' => $attachment['name'], 'file_data' => $data]]
                : ['type' => 'image_url', 'image_url' => ['url' => $data]];
        }
        // Files belong to the turn that carried them (for a continuation, its instruction turn).
        if ($files !== []) {
            $turn = array_key_last(array_filter($messages, fn (array $message): bool => $message['role'] === 'user'));
            $own = $turn === null ? '' : $messages[$turn]['content'];
            $parts = [...$files, ...(is_array($own) ? $own : ($own === '' || $own === null ? [] : [['type' => 'text', 'text' => $own]]))];
            $binary = collect($parts)->contains(fn (array $part): bool => $part['type'] !== 'text');
            $content = $binary ? $parts : implode("\n\n", array_column($parts, 'text'));
            if ($turn === null) {
                $messages[] = ['role' => 'user', 'content' => $content];
            } else {
                $messages[$turn]['content'] = $content;
            }
        }
        if ($notes !== null) {
            $position = count($messages) > 0 && $messages[0]['role'] === 'system' ? 1 : 0;
            array_splice($messages, $position, 0, [['role' => 'user', 'content' => $notes]]);
        }
        if ($messages !== [] && $messages[0]['role'] === 'system') {
            $content = $messages[0]['content'];
            $messages[0]['content'] = is_array($content)
                ? [['type' => 'text', 'text' => $systemPrompt], ...$content]
                : $systemPrompt."\n\n".$content;
        } else {
            array_unshift($messages, ['role' => 'system', 'content' => $systemPrompt]);
        }

        return $messages;
    }

    private function replay(ChatOperation $operation, bool $workspaceProtocol): void
    {
        $offset = 0;
        $previousStatus = null;
        $deadline = microtime(true) + 150;
        do {
            $operation = $operation->fresh();
            if (! $operation) {
                return;
            }
            $conversation = ChatConversation::query()->where('user_id', $operation->user_id)->where('conversation_key', $operation->conversation_id)->first();
            if (! $conversation || $conversation->deleted_at) {
                return;
            }
            $this->expire($operation);
            $operation = $operation->fresh();
            if (! $operation) {
                return;
            }
            if ($workspaceProtocol && $previousStatus === null) {
                $this->event('operation', [...$this->eventState($operation), 'partial_content' => $operation->partial_content]);
                $offset = strlen($operation->partial_content);
            }
            if ($workspaceProtocol && $previousStatus !== $operation->status) {
                $this->event('state', $this->eventState($operation));
            }
            $previousStatus = $operation->status;
            $newText = substr($operation->partial_content, $offset);
            if ($newText !== '') {
                $this->event(null, $this->chunk($operation, $newText));
                $offset = strlen($operation->partial_content);
            }
            if (! $operation->isActive()) {
                if ($operation->status === 'completed') {
                    $chunk = $this->chunk($operation, '', 'stop');
                    if ($operation->usage !== null) {
                        $chunk['usage'] = $operation->usage;
                    }
                    $this->event(null, $chunk);
                }
                $this->terminal($operation, $workspaceProtocol);
                return;
            }
            if (connection_aborted() || microtime(true) >= $deadline) {
                return;
            }
            usleep(200000);
        } while (true);
    }

    private function terminal(ChatOperation $operation, bool $workspaceProtocol): void
    {
        if ($operation->status === 'failed' || (! $workspaceProtocol && $operation->status === 'stopped')) {
            $error = ['message' => $operation->error ?? 'The response was stopped. Saved content is partial.', 'type' => 'upstream_error'];
            $this->event($workspaceProtocol ? 'error' : null, $workspaceProtocol
                ? [...$error, 'operation_id' => $operation->id, 'conversation_id' => $operation->conversation_id, 'status' => $operation->status]
                : ['error' => $error]);
        }
        if ($workspaceProtocol) {
            $this->event('state', $this->eventState($operation));
            $this->event('final', [
                'operation_id' => $operation->id, 'conversation_id' => $operation->conversation_id, 'status' => $operation->status,
                'message' => [
                    'id' => $operation->assistant_message_id, 'role' => 'assistant', 'content' => $operation->partial_content,
                    'model' => $operation->model, 'status' => $operation->status, 'operation_id' => $operation->id,
                    'attachments' => [], 'created_at' => $operation->created_at?->toISOString(),
                    'retry_of' => $operation->retry_of, 'continuation_of' => $operation->continuation_of,
                ],
                'usage' => $operation->usage, 'usage_known' => $operation->usage !== null,
            ]);
        }
        if ($workspaceProtocol || $operation->status === 'completed') {
            echo "data: [DONE]\n\n";
            $this->flush();
        }
    }

    private function owned(User $user, string $id): ChatOperation
    {
        abort_unless($user->hasPermission('chat'), 403, 'Chat access is unavailable.');
        $operation = ChatOperation::query()->where('user_id', $user->id)->whereKey($id)->firstOrFail();
        $conversation = ChatConversation::query()->where('user_id', $user->id)->where('conversation_key', $operation->conversation_id)->first();
        abort_if(! $conversation || $conversation->deleted_at, 410, 'This conversation was deleted.');

        return $operation;
    }

    private function lockedConversation(ChatOperation $operation): ?ChatConversation
    {
        return ChatConversation::query()->where('user_id', $operation->user_id)->where('conversation_key', $operation->conversation_id)->lockForUpdate()->first();
    }

    private function expire(ChatOperation $operation): void
    {
        if (! $operation->isActive() || ($operation->heartbeat_at ?? $operation->created_at)->gte(now()->subMinutes(3))) {
            return;
        }
        DB::transaction(function () use ($operation): void {
            User::query()->whereKey($operation->user_id)->lockForUpdate()->firstOrFail();
            $conversation = $this->lockedConversation($operation);
            $locked = ChatOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($conversation && ! $conversation->deleted_at) {
                $this->expireLocked($locked);
            }
        }, 3);
    }

    private function expireLocked(ChatOperation $operation): void
    {
        if ($operation->isActive() && ($operation->heartbeat_at ?? $operation->created_at)->lt(now()->subMinutes(3))) {
            $operation->forceFill([
                'status' => 'failed', 'finished_at' => now(),
                'error' => 'The connection was interrupted. Saved content is partial; use an explicit retry for a new request.',
            ])->save();
            $this->updateMessage($operation);
            $this->recordUsage($operation);
        }
    }

    private function present(ChatOperation $operation): array
    {
        return [
            'id' => $operation->id, 'conversation_id' => $operation->conversation_id, 'status' => $operation->status,
            'partial_content' => $operation->partial_content, 'user_message_id' => $operation->user_message_id,
            'assistant_message_id' => $operation->assistant_message_id, 'model' => $operation->model,
            'error' => $operation->error, 'can_stop' => $operation->isActive(),
            'usage' => $operation->usage, 'usage_known' => $operation->usage !== null,
        ];
    }

    private function eventState(ChatOperation $operation): array
    {
        $state = $this->present($operation);
        unset($state['id'], $state['partial_content']);

        return ['operation_id' => $operation->id, ...$state];
    }

    private function event(?string $name, array $payload): void
    {
        if ($name !== null) {
            echo 'event: '.$name."\n";
        }
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private function chunk(ChatOperation $operation, string $text, ?string $finish = null): array
    {
        return [
            'id' => $operation->id, 'object' => 'chat.completion.chunk', 'model' => $operation->model,
            'choices' => [['index' => 0, 'delta' => ['content' => $text], 'finish_reason' => $finish]],
        ];
    }

    private function measuredUsage(mixed $usage): ?array
    {
        if (! is_array($usage)) {
            return null;
        }
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            if (! is_int($usage[$key] ?? null) || $usage[$key] < 0) {
                return null;
            }
        }

        return array_intersect_key($usage, array_flip(['prompt_tokens', 'completion_tokens', 'total_tokens', 'prompt_tokens_details', 'completion_tokens_details']));
    }

    private function textContent(mixed $content): string
    {
        if ($content === null || is_string($content)) {
            return (string) $content;
        }
        $textParts = array_filter($content, fn (array $part): bool => ($part['type'] ?? null) === 'text');
        $text = implode("\n", array_column($textParts, 'text'));

        return $text !== '' || count($textParts) === count($content) ? $text : '[Image/File attachment]';
    }

    /**
     * Saved turns for the provider. A stopped or failed answer that was later continued is the start of the
     * accepted answer, so its chain is sent whole; unrelated failed attempts stay out. A turn that carried only
     * files is labelled, because providers reject empty messages and earlier files are not sent again.
     */
    private function history(User $user, string $conversationKey, int $lastId, ?int $continuing): array
    {
        $rows = DB::table('chat_history')->where('user_id', $user->id)->where('conversation_id', $conversationKey)
            ->where('id', '<=', $lastId)->whereIn('role', ['user', 'assistant'])->orderBy('id')
            ->get(['id', 'role', 'content', 'status', 'attachment_ids']);
        $included = $rows->filter(fn ($row): bool => $row->status === 'completed' || (int) $row->id === $continuing)
            ->mapWithKeys(fn ($row): array => [(int) $row->id => true])->all();
        $continued = ChatOperation::query()->where('user_id', $user->id)->where('conversation_id', $conversationKey)
            ->whereNotNull('continuation_of')->pluck('continuation_of', 'assistant_message_id')->all();
        do {
            $grown = false;
            foreach ($continued as $continuation => $original) {
                if (isset($included[(int) $continuation]) && ! isset($included[(int) $original])) {
                    $included[(int) $original] = $grown = true;
                }
            }
        } while ($grown);
        $messages = [];
        foreach ($rows as $row) {
            if (! isset($included[(int) $row->id])) {
                continue;
            }
            $content = (string) $row->content;
            if ($row->role === 'user' && trim($content) === '') {
                $names = ChatAttachment::query()->where('user_id', $user->id)->whereKey($this->decodedIds($row->attachment_ids))->pluck('name')->all();
                $content = '[User sent only attachments'.($names === [] ? '' : ': '.implode(', ', $names)).']';
            }
            $previous = array_key_last($messages);
            if ($row->role === 'assistant' && $previous !== null && $messages[$previous]['role'] === 'assistant') {
                $messages[$previous]['content'] .= $content;
            } else {
                $messages[] = ['role' => $row->role, 'content' => $content];
            }
        }

        return $messages;
    }

    private function decodedIds(?string $ids): array
    {
        return $ids === null ? [] : (json_decode($ids, true, flags: JSON_THROW_ON_ERROR) ?: []);
    }
}
