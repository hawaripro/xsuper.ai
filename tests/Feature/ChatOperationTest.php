<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ChatAttachment;
use App\Models\ChatOperation;
use App\Models\MediaAsset;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AiProviderEndpoint;
use App\Services\AiProxyService;
use App\Services\ChatCapabilityService;
use App\Services\ChatOperationService;
use App\Services\ChatWorkspaceService;
use App\Services\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatOperationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $provider = AiProviderProfile::create([
            'slug' => 'workspace-chat', 'name' => 'Chat', 'protocol' => 'openai',
            'base_url' => 'https://chat.example.test/v1', 'api_key' => 'test-only-key',
            'is_enabled' => true, 'status' => 'healthy',
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'workspace-chat', 'upstream_model_id' => 'private-chat',
            'display_name' => 'Chat', 'category' => 'chat', 'input_modalities' => ['text'],
            'output_modalities' => ['text'], 'is_enabled' => true, 'is_available' => true,
        ]);
        foreach (['input_tokens' => 1, 'output_tokens' => 2] as $meter => $price) {
            UsageRate::create([
                'service' => 'api', 'model' => 'workspace-chat', 'meter' => $meter, 'label' => $meter,
                'unit' => '1M tokens', 'price_usd' => $price, 'price_idr' => 16000 * $price, 'is_active' => true,
            ]);
        }
    }

    public function test_same_key_replays_saved_answer_without_another_user_turn_or_provider_charge(): void
    {
        $this->fakeAnswer();
        $user = $this->member();
        $input = $this->input();
        $first = $this->actingAs($user)->postJson('/api/c/s', $input)->assertOk()->streamedContent();
        $second = $this->postJson('/api/c/s', $input)->assertOk()->streamedContent();

        $operation = ChatOperation::query()->sole();
        $this->assertSame('completed', $operation->status);
        $this->assertSame('Saved answer', $operation->partial_content);
        $this->assertStringContainsString('event: final', $first);
        $this->assertStringContainsString('Saved answer', $second);
        $this->assertSame(1, DB::table('chat_history')->where('role', 'user')->count());
        $this->assertSame(1, DB::table('chat_history')->where('role', 'assistant')->count());
        $this->assertSame(1, DB::table('usage_logs')->where('model', 'workspace-chat')->count());
        Http::assertSentCount(1);

        $input['messages'][0]['content'] = 'A different question';
        $this->postJson('/api/c/s', $input)->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_two_admissions_share_one_claim_and_stopping_before_submission_sends_nothing(): void
    {
        Http::fake();
        $user = $this->member();
        $service = app(ChatOperationService::class);
        $input = $this->input();
        [$first, $created] = $service->admit($user, $input);
        [$replay, $replayed] = $service->admit($user, $input);
        $this->assertTrue($created);
        $this->assertFalse($replayed);
        $this->assertSame($first->id, $replay->id);
        $service->stop($user, $first->id);

        $this->actingAs($user)->postJson('/api/c/s', $input)->assertOk()->streamedContent();
        $this->assertSame('stopped', $first->fresh()->status);
        $this->assertSame(1, DB::table('chat_history')->where('role', 'user')->count());
        Http::assertNothingSent();
    }

    public function test_stop_keeps_partial_and_unknown_usage_without_marking_completion(): void
    {
        $user = $this->member();
        $proxy = \Mockery::mock(AiProxyService::class)->makePartial();
        $proxy->shouldReceive('streamChatCompletion')->once()->andReturnUsing(function () use ($user) {
            yield ['choices' => [['index' => 0, 'delta' => ['content' => 'Partial saved'], 'finish_reason' => null]]];
            app(ChatOperationService::class)->stop($user, ChatOperation::query()->sole()->id);
            yield ['choices' => [['index' => 0, 'delta' => ['content' => 'Not accepted'], 'finish_reason' => null]]];
        });
        $this->app->instance(AiProxyService::class, $proxy);

        $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertOk()->streamedContent();
        $operation = ChatOperation::query()->sole();
        $this->assertSame('stopped', $operation->status);
        $this->assertSame('Partial saved', $operation->partial_content);
        $this->assertNull($operation->usage);
        $this->assertDatabaseHas('chat_history', ['id' => $operation->assistant_message_id, 'content' => 'Partial saved', 'status' => 'stopped']);
        $this->assertTrue($operation->billing['estimated']);
        $this->assertDatabaseHas('usage_logs', ['user_id' => $user->id, 'completion_tokens' => 5]);
    }

    public function test_stop_settles_once_without_accepting_later_text_or_usage(): void
    {
        $user = $this->member();
        $proxy = \Mockery::mock(AiProxyService::class)->makePartial();
        $proxy->shouldReceive('streamChatCompletion')->once()->andReturnUsing(function () use ($user) {
            yield ['choices' => [['index' => 0, 'delta' => ['content' => 'Saved partial'], 'finish_reason' => null]]];
            app(ChatOperationService::class)->stop($user, ChatOperation::query()->sole()->id);
            yield [
                'choices' => [['index' => 0, 'delta' => ['content' => 'Too late'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3, 'total_tokens' => 13],
            ];
        });
        $this->app->instance(AiProxyService::class, $proxy);
        $input = $this->input();
        $this->actingAs($user)->postJson('/api/c/s', $input)->assertOk()->streamedContent();
        $this->postJson('/api/c/s', $input)->assertOk()->streamedContent();
        $operation = ChatOperation::query()->sole();
        $this->assertSame('stopped', $operation->status);
        $this->assertSame('Saved partial', $operation->partial_content);
        $this->assertNull($operation->usage);
        $this->assertTrue($operation->billing['estimated']);
        $this->assertSame(1, DB::table('usage_logs')->where('model', 'workspace-chat')->count());
        $this->assertDatabaseHas('usage_logs', ['user_id' => $user->id, 'prompt_tokens' => $operation->billing['input_estimate'], 'completion_tokens' => 5]);
    }

    public function test_a_client_leaving_after_the_final_chunk_keeps_the_complete_answer_completed(): void
    {
        $user = $this->member();
        $client = new \stdClass;
        $client->gone = false;
        $proxy = \Mockery::mock(AiProxyService::class)->makePartial();
        $proxy->shouldReceive('streamChatCompletion')->once()->andReturnUsing(function () use ($client) {
            yield [
                'choices' => [['index' => 0, 'delta' => ['content' => 'Complete answer'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2, 'total_tokens' => 6],
            ];
            // PHP only notices a closed tab when writing the final event fails.
            $client->gone = true;
        });
        $this->app->instance(AiProxyService::class, $proxy);
        $this->app->instance(ChatOperationService::class, new class($proxy, app(ChatWorkspaceService::class), app(ChatCapabilityService::class), $client) extends ChatOperationService
        {
            public function __construct(AiProxyService $proxy, ChatWorkspaceService $workspaces, ChatCapabilityService $capabilities, private readonly \stdClass $client)
            {
                parent::__construct($proxy, $workspaces, $capabilities, app(UsageBillingService::class));
            }

            protected function clientDisconnected(): bool
            {
                return $this->client->gone;
            }
        });

        $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertOk()->streamedContent();
        $operation = ChatOperation::query()->sole();
        $this->assertSame('completed', $operation->status);
        $this->assertNull($operation->stop_requested_at);
        $this->assertDatabaseHas('chat_history', ['id' => $operation->assistant_message_id, 'content' => 'Complete answer', 'status' => 'completed']);
        $this->assertDatabaseHas('usage_logs', ['user_id' => $user->id, 'total_tokens' => 6]);
    }

    public function test_deleted_conversation_cannot_be_resurrected_by_late_finalization_or_replay(): void
    {
        $user = $this->member();
        $proxy = \Mockery::mock(AiProxyService::class)->makePartial();
        $proxy->shouldReceive('streamChatCompletion')->once()->andReturnUsing(function () use ($user) {
            yield ['choices' => [['index' => 0, 'delta' => ['content' => 'Before deletion'], 'finish_reason' => null]]];
            app(\App\Services\ChatWorkspaceService::class)->deleteConversation($user, 'operation-chat');
            yield ['choices' => [['index' => 0, 'delta' => ['content' => 'Late output'], 'finish_reason' => 'stop']]];
        });
        $this->app->instance(AiProxyService::class, $proxy);
        $input = $this->input();
        $this->actingAs($user)->postJson('/api/c/s', $input)->assertOk()->streamedContent();
        $this->assertSame(0, DB::table('chat_history')->where('conversation_id', 'operation-chat')->count());
        $this->postJson('/api/c/s', $input)->assertGone();
        $this->assertSame('stopped', ChatOperation::query()->sole()->status);
    }

    public function test_unsupported_tool_fails_before_saving_a_turn_or_contacting_provider(): void
    {
        Http::fake();
        $input = $this->input();
        $input['tools']['web_search'] = true;
        $this->actingAs($this->member())->postJson('/api/c/s', $input)
            ->assertUnprocessable()->assertJsonValidationErrors('tools.web_search');
        $this->assertDatabaseCount('chat_operations', 0);
        $this->assertDatabaseCount('chat_history', 0);
        Http::assertNothingSent();
    }

    public function test_retry_reuses_the_original_user_turn_and_cannot_target_another_chat(): void
    {
        $body = 'data: '.json_encode(['choices' => [['index' => 0, 'delta' => ['content' => 'Interrupted'], 'finish_reason' => null]]])."\n\n";
        Http::fake(['https://chat.example.test/v1/chat/completions' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);
        $user = $this->member();
        $input = $this->input();
        $this->actingAs($user)->postJson('/api/c/s', $input)->assertOk()->streamedContent();
        $original = ChatOperation::query()->sole();
        $this->assertSame('failed', $original->status);
        $this->fakeAnswer();
        $input['client_request_id'] = (string) Str::uuid();
        $input['retry_of'] = $original->assistant_message_id;
        $this->postJson('/api/c/s', $input)->assertOk()->streamedContent();
        $this->assertSame(1, DB::table('chat_history')->where('role', 'user')->count());
        $this->assertSame(2, DB::table('chat_history')->where('role', 'assistant')->count());
        $input['client_request_id'] = (string) Str::uuid();
        $input['conversation_id'] = 'other-chat';
        $this->postJson('/api/c/s', $input)->assertUnprocessable();
    }

    public function test_notes_and_text_files_are_frozen_user_context_not_system_authority(): void
    {
        Storage::fake('local');
        $this->fakeAnswer();
        $user = $this->member();
        $conversation = app(ChatWorkspaceService::class)->resolveConversation($user, 'operation-chat', true);
        $conversation->workspace->update(['notes' => 'Original saved notes', 'version' => 2]);
        $attachment = $this->attachment($user, $conversation, 'text/plain', 'Owned text file content');
        $input = $this->input();
        $input['attachment_ids'] = [$attachment->id];
        app(ChatOperationService::class)->admit($user, $input);
        $conversation->workspace->update(['notes' => 'Later unrelated notes', 'version' => 3]);

        $this->actingAs($user)->postJson('/api/c/s', $input)->assertOk()->streamedContent();
        Http::assertSent(function ($request): bool {
            $system = implode("\n", array_column(array_filter($request['messages'], fn ($message): bool => $message['role'] === 'system'), 'content'));
            $user = implode("\n", array_column(array_filter($request['messages'], fn ($message): bool => $message['role'] === 'user'), 'content'));

            return str_contains($user, 'Original saved notes') && str_contains($user, 'Owned text file content')
                && ! str_contains($user, 'Later unrelated notes') && ! str_contains($system, 'Original saved notes')
                && ! str_contains($system, 'Owned text file content') && ! isset($request['_is_cancelled']) && ! isset($request['_route_snapshot']);
        });
    }

    public function test_native_pdf_is_typed_binary_and_other_conversation_files_are_rejected(): void
    {
        Storage::fake('local');
        $profile = AiModelProfile::query()->where('model_id', 'workspace-chat')->firstOrFail();
        $profile->update(['input_modalities' => ['text', 'image']]);
        $profile->provider->update(['protocol' => 'anthropic']);
        $user = $this->member();
        $conversation = app(ChatWorkspaceService::class)->resolveConversation($user, 'operation-chat', true);
        $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
        $attachment = $this->attachment($user, $conversation, 'application/pdf', $pdf);
        $events = [
            ['type' => 'message_start', 'message' => ['id' => 'pdf-answer', 'type' => 'message', 'role' => 'assistant', 'model' => 'private-chat', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 12, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Native document received']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 3]],
            ['type' => 'message_stop'],
        ];
        $body = implode('', array_map(fn ($event): string => 'event: '.$event['type']."\ndata: ".json_encode($event)."\n\n", $events));
        Http::fake(['https://chat.example.test/v1/messages' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);
        $input = $this->input();
        $input['attachment_ids'] = [$attachment->id];
        $this->actingAs($user)->postJson('/api/c/s', $input)->assertOk()->streamedContent();
        Http::assertSent(function ($request) use ($pdf): bool {
            foreach ($request['messages'] as $message) {
                foreach ($message['content'] as $part) {
                    if ($part['type'] === 'document') {
                        return $message['role'] === 'user' && $part['source']['media_type'] === 'application/pdf'
                            && base64_decode($part['source']['data'], true) === $pdf;
                    }
                }
            }

            return false;
        });
        $input['conversation_id'] = 'different-chat';
        $input['client_request_id'] = (string) Str::uuid();
        $this->postJson('/api/c/s', $input)->assertUnprocessable()->assertJsonValidationErrors('attachment_ids');
        Http::assertSentCount(1);
    }

    public function test_attachment_only_turns_send_their_files_and_remain_valid_history(): void
    {
        Storage::fake('local');
        AiModelProfile::query()->where('model_id', 'workspace-chat')->firstOrFail()->provider->update(['protocol' => 'anthropic']);
        Http::fake(['https://chat.example.test/v1/messages' => Http::sequence()
            ->push($this->anthropicAnswer('Summary ready'), 200, ['Content-Type' => 'text/event-stream'])
            ->push($this->anthropicAnswer('Rising'), 200, ['Content-Type' => 'text/event-stream'])]);
        $user = $this->member();
        $conversation = app(ChatWorkspaceService::class)->resolveConversation($user, 'operation-chat', true);
        $input = $this->input();
        $input['messages'] = [['role' => 'user', 'content' => '']];
        $input['attachment_ids'] = [$this->attachment($user, $conversation, 'text/plain', 'Quarterly numbers')->id];
        $this->actingAs($user)->postJson('/api/c/s', $input)->assertOk()->streamedContent();
        $next = $this->input();
        $next['messages'] = [['role' => 'user', 'content' => 'And the trend?']];
        $this->postJson('/api/c/s', $next)->assertOk()->streamedContent();

        $this->assertSame(['completed', 'completed'], ChatOperation::query()->orderBy('created_at')->orderBy('id')->pluck('status')->all());
        $sent = Http::recorded()->map(fn (array $pair): array => $pair[0]['messages'])->values();
        $text = fn (array $message): string => implode("\n", array_column($message['content'], 'text'));
        // The files belong to the turn that carried them, not a detached context message.
        $this->assertSame(['user'], array_column($sent[0], 'role'));
        $this->assertStringContainsString('Quarterly numbers', $text($sent[0][0]));
        $this->assertSame(['user', 'assistant', 'user'], array_column($sent[1], 'role'));
        $this->assertStringContainsString('source.txt', $text($sent[1][0]));
        $this->assertSame('And the trend?', $text($sent[1][2]));
    }

    public function test_a_continued_partial_answer_stays_whole_in_later_context(): void
    {
        $chunk = fn (string $text, ?string $finish): string => 'data: '.json_encode(['id' => 'answer', 'model' => 'private-chat',
            'choices' => [['index' => 0, 'delta' => ['content' => $text], 'finish_reason' => $finish]]])."\n\n";
        Http::fake(['https://chat.example.test/v1/chat/completions' => Http::sequence()
            ->push($chunk('The first half', null), 200, ['Content-Type' => 'text/event-stream'])
            ->push($chunk(' and the rest.', 'stop')."data: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream'])
            ->push($chunk('Next answer', 'stop')."data: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
        $user = $this->member();
        $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertOk()->streamedContent();
        $interrupted = ChatOperation::query()->sole();
        $this->assertSame('failed', $interrupted->status);
        $continue = $this->input();
        $continue['continuation_of'] = $interrupted->assistant_message_id;
        $this->postJson('/api/c/s', $continue)->assertOk()->streamedContent();
        $next = $this->input();
        $next['messages'] = [['role' => 'user', 'content' => 'Next question']];
        $this->postJson('/api/c/s', $next)->assertOk()->streamedContent();

        $history = array_values(array_filter(Http::recorded()->last()[0]['messages'], fn (array $message): bool => $message['role'] !== 'system'));
        $this->assertSame([['user', 'Question'], ['assistant', 'The first half and the rest.'], ['user', 'Next question']],
            array_map(fn (array $message): array => [$message['role'], $message['content']], $history));
    }

    public function test_only_the_latest_answer_can_be_retried_or_continued_so_context_stays_in_order(): void
    {
        $chunk = fn (string $text, ?string $finish): string => 'data: '.json_encode(['id' => 'answer', 'model' => 'private-chat',
            'choices' => [['index' => 0, 'delta' => ['content' => $text], 'finish_reason' => $finish]]])."\n\n";
        Http::fake(['https://chat.example.test/v1/chat/completions' => Http::sequence()
            ->push($chunk('Half an answer', null), 200, ['Content-Type' => 'text/event-stream'])
            ->push($chunk('Second answer', 'stop')."data: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
        $user = $this->member();
        $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertOk()->streamedContent();
        $older = ChatOperation::query()->sole();
        $this->assertSame('failed', $older->status);
        $next = $this->input();
        $next['messages'] = [['role' => 'user', 'content' => 'Second question']];
        $this->postJson('/api/c/s', $next)->assertOk()->streamedContent();

        // An older answer's retry or continuation would land after the newer turn and scramble the saved context.
        $retry = $this->input();
        $retry['retry_of'] = $older->assistant_message_id;
        $this->postJson('/api/c/s', $retry)->assertUnprocessable()->assertJsonValidationErrors('retry_of');
        $continue = $this->input();
        $continue['continuation_of'] = $older->assistant_message_id;
        $continue['continuation'] = true;
        $this->postJson('/api/c/s', $continue)->assertUnprocessable()->assertJsonValidationErrors('continuation_of');
        Http::assertSentCount(2);
        $this->assertSame(2, ChatOperation::query()->count());
    }

    public function test_a_continuation_and_a_retry_name_the_answer_they_follow(): void
    {
        $chunk = fn (string $text, ?string $finish): string => 'data: '.json_encode(['id' => 'answer', 'model' => 'private-chat',
            'choices' => [['index' => 0, 'delta' => ['content' => $text], 'finish_reason' => $finish]]])."\n\n";
        Http::fake(['https://chat.example.test/v1/chat/completions' => Http::sequence()
            ->push($chunk('Half an answer', null), 200, ['Content-Type' => 'text/event-stream'])
            ->push($chunk(' and its end.', null), 200, ['Content-Type' => 'text/event-stream'])
            ->push($chunk('A fresh answer', 'stop')."data: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
        $user = $this->member();
        $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertOk()->streamedContent();
        $first = ChatOperation::query()->sole()->assistant_message_id;
        $continue = $this->input();
        $continue['continuation_of'] = $first;
        $continue['continuation'] = true;
        $this->postJson('/api/c/s', $continue)->assertOk()->streamedContent();
        $continued = ChatOperation::query()->whereNotNull('continuation_of')->sole()->assistant_message_id;
        $retry = $this->input();
        $retry['retry_of'] = $continued;
        $stream = $this->postJson('/api/c/s', $retry)->assertOk()->streamedContent();
        $retried = ChatOperation::query()->whereNotNull('retry_of')->sole()->assistant_message_id;

        // The streamed final message and the reloaded conversation both say which answer a new one follows.
        preg_match('/event: final\ndata: (.+)\n/', $stream, $final);
        $this->assertSame($continued, json_decode($final[1], true)['message']['retry_of']);
        $relations = collect($this->getJson('/api/c/h/operation-chat')->assertOk()->json('messages'))
            ->where('role', 'assistant')->mapWithKeys(fn (array $message): array => [$message['id'] => [$message['continuation_of'], $message['retry_of']]])->all();
        $this->assertSame([$first => [null, null], $continued => [$first, null], $retried => [null, $continued]], $relations);
    }

    private function member(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        Wallet::credit($user->id, 1_000_000, 'Test opening balance');

        return $user;
    }

    private function anthropicAnswer(string $text): string
    {
        $events = [
            ['type' => 'message_start', 'message' => ['id' => 'answer', 'type' => 'message', 'role' => 'assistant', 'model' => 'private-chat', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 12, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 3]],
            ['type' => 'message_stop'],
        ];

        return implode('', array_map(fn ($event): string => 'event: '.$event['type']."\ndata: ".json_encode($event)."\n\n", $events));
    }

    private function attachment(User $user, $conversation, string $mime, string $bytes): ChatAttachment
    {
        $path = 'chat-fixtures/'.Str::uuid();
        Storage::disk('local')->put($path, $bytes);
        $asset = MediaAsset::create([
            'user_id' => $user->id, 'media_type' => 'document', 'role' => 'document', 'mime' => $mime,
            'storage_disk' => 'local', 'storage_path' => $path, 'original_name' => $mime === 'application/pdf' ? 'source.pdf' : 'source.txt',
            'size_bytes' => strlen($bytes), 'signature_ok' => true, 'retention_status' => 'active', 'expires_at' => now()->addDay(),
        ]);

        return ChatAttachment::create([
            'user_id' => $user->id, 'workspace_id' => $conversation->workspace_id, 'conversation_id' => $conversation->id,
            'asset_id' => $asset->id, 'name' => $asset->original_name,
        ]);
    }

    private function input(): array
    {
        return [
            'model' => 'workspace-chat', 'conversation_id' => 'operation-chat',
            'client_request_id' => (string) Str::uuid(), 'stream_protocol' => 'workspace_v2',
            'messages' => [['role' => 'user', 'content' => 'Question']],
            'attachment_ids' => [], 'tools' => ['file_analysis' => true],
        ];
    }

    private function fakeAnswer(): void
    {
        $body = 'data: '.json_encode([
            'id' => 'answer', 'model' => 'private-chat',
            'choices' => [['index' => 0, 'delta' => ['content' => 'Saved answer'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 2, 'total_tokens' => 11],
        ])."\n\ndata: [DONE]\n\n";
        Http::fake(['https://chat.example.test/v1/chat/completions' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);
    }
}
