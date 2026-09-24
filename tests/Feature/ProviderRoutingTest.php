<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ApiKey;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AiProviderEndpoint;
use App\Services\AiProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderRoutingTest extends TestCase
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
        config(['services.ai_proxy.url' => 'https://legacy.example.test', 'services.ai_proxy.key' => 'legacy-fixture-key']);
        Http::fake(['https://legacy.example.test/v1/models' => Http::response(['data' => []])]);
    }

    public function test_two_public_models_route_to_their_own_provider_without_changing_public_ids(): void
    {
        $openai = $this->provider('openai');
        $anthropic = $this->provider('anthropic');
        $this->model($openai, 'public-gpt', 'private-gpt-model');
        $this->model($anthropic, 'public-claude', 'private-claude-model');
        Http::fake([
            'https://openai.example.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-one', 'object' => 'chat.completion', 'model' => 'private-gpt-model',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'OpenAI answer'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 6, 'completion_tokens' => 3, 'total_tokens' => 9],
            ]),
            'https://anthropic.example.test/v1/messages' => Http::response([
                'id' => 'msg_two', 'type' => 'message', 'role' => 'assistant', 'model' => 'private-claude-model',
                'content' => [['type' => 'text', 'text' => 'Anthropic answer']], 'stop_reason' => 'end_turn', 'stop_sequence' => null,
                'usage' => ['input_tokens' => 7, 'output_tokens' => 4, 'cache_read_input_tokens' => 2, 'cache_creation_input_tokens' => 1],
            ]),
        ]);
        $service = app(AiProxyService::class);
        $first = $service->chatCompletion([['role' => 'user', 'content' => 'First request']], 'public-gpt');
        $second = $service->chatCompletion([['role' => 'system', 'content' => 'Brief answers'], ['role' => 'user', 'content' => 'Second request']], 'public-claude');

        $this->assertSame('OpenAI answer', $first['choices'][0]['message']['content']);
        $this->assertSame('public-gpt', $first['model']);
        $this->assertSame('Anthropic answer', $second['choices'][0]['message']['content']);
        $this->assertSame('public-claude', $second['model']);
        $this->assertSame(10, $second['usage']['prompt_tokens']);
        Http::assertSent(fn ($request) => $request->url() === 'https://openai.example.test/v1/chat/completions'
            && $request['model'] === 'private-gpt-model' && $request->hasHeader('Authorization', 'Bearer openai-fixture-key'));
        Http::assertSent(fn ($request) => $request->url() === 'https://anthropic.example.test/v1/messages'
            && $request['model'] === 'private-claude-model' && $request->hasHeader('x-api-key', 'anthropic-fixture-key')
            && isset($request['system']) && $request['messages'][0]['role'] === 'user');
    }

    public function test_anthropic_api_response_settles_actual_usage_and_keeps_tools(): void
    {
        $provider = $this->provider('anthropic');
        $this->model($provider, 'public-tools', 'private-tools');
        $user = User::factory()->create(['role' => 'admin']);
        Wallet::credit($user->id, 1_000_000, 'Fixture balance');
        $key = ApiKey::generate($user->id, 'Fixture');
        foreach (['input_tokens' => 1, 'output_tokens' => 2] as $meter => $price) {
            UsageRate::create(['service' => 'api', 'meter' => $meter, 'model' => 'public-tools', 'label' => $meter, 'unit' => '1M tokens', 'price_usd' => $price, 'price_idr' => 16000 * $price, 'is_active' => true]);
        }
        Http::fake(['https://anthropic.example.test/v1/messages' => Http::response([
            'id' => 'msg_tool', 'type' => 'message', 'role' => 'assistant', 'model' => 'private-tools',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'weather', 'input' => ['city' => 'Jakarta']]],
            'stop_reason' => 'tool_use', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'cache_read_input_tokens' => 3, 'cache_creation_input_tokens' => 2],
        ])]);

        $response = $this->withToken($key->plainKey)->postJson('/v1/chat/completions', [
            'model' => 'public-tools', 'max_tokens' => 20,
            'messages' => [['role' => 'user', 'content' => 'Weather?']],
            'tools' => [['type' => 'function', 'function' => ['name' => 'weather', 'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]]]]],
            'tool_choice' => 'auto',
        ])->assertOk();
        $response->assertJsonPath('model', 'public-tools')->assertJsonPath('choices.0.finish_reason', 'tool_calls');
        $this->assertSame(['city' => 'Jakarta'], json_decode($response->json('choices.0.message.tool_calls.0.function.arguments'), true));
        $this->assertSame(999975, Wallet::balance($user->id));
        $this->assertDatabaseHas('usage_logs', ['user_id' => $user->id, 'model' => 'public-tools', 'prompt_tokens' => 15, 'completion_tokens' => 5]);
    }

    public function test_anthropic_stream_persists_complete_answer_and_actual_token_usage(): void
    {
        $provider = $this->provider('anthropic');
        $this->model($provider, 'public-stream', 'private-stream');
        $events = [
            ['type' => 'message_start', 'message' => ['id' => 'msg_stream', 'type' => 'message', 'role' => 'assistant', 'model' => 'private-stream', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 11, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello ']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'dunia']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 3]],
            ['type' => 'message_stop'],
        ];
        Http::fake(['https://anthropic.example.test/v1/messages' => Http::response($this->sse($events), 200, ['Content-Type' => 'text/event-stream'])]);
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/c/s', [
            'model' => 'public-stream', 'conversation_id' => 'provider-stream', 'messages' => [['role' => 'user', 'content' => 'Hello']],
        ])->assertOk();
        $stream = $response->streamedContent();
        $this->assertSame(1, substr_count($stream, 'data: [DONE]'));
        $this->assertStringNotContainsString('private-stream', $stream);
        $this->assertDatabaseHas('chat_history', ['user_id' => $user->id, 'conversation_id' => 'provider-stream', 'role' => 'assistant', 'content' => 'Hello dunia']);
        $this->assertDatabaseHas('usage_logs', ['user_id' => $user->id, 'model' => 'public-stream', 'prompt_tokens' => 11, 'completion_tokens' => 3]);
    }

    public function test_incomplete_stream_reports_error_and_preserves_partial_as_failed(): void
    {
        $provider = $this->provider('openai');
        $this->model($provider, 'public-partial', 'private-partial');
        $body = 'data: '.json_encode(['id' => 'chatcmpl-partial', 'model' => 'private-partial', 'choices' => [['index' => 0, 'delta' => ['content' => 'Incomplete answer'], 'finish_reason' => null]]])."\n\n";
        Http::fake(['https://openai.example.test/v1/chat/completions' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/c/s', [
            'model' => 'public-partial', 'conversation_id' => 'incomplete-stream', 'messages' => [['role' => 'user', 'content' => 'Hello']],
        ])->assertOk();
        $stream = $response->streamedContent();
        $this->assertStringContainsString('"error"', $stream);
        $this->assertDatabaseHas('chat_history', [
            'conversation_id' => 'incomplete-stream', 'role' => 'assistant',
            'content' => 'Incomplete answer', 'status' => 'failed',
        ]);
        $this->assertDatabaseMissing('chat_history', ['conversation_id' => 'incomplete-stream', 'role' => 'assistant', 'status' => 'completed']);
        $this->assertDatabaseMissing('usage_logs', ['user_id' => $user->id, 'model' => 'public-partial']);
    }

    public function test_disabling_provider_removes_models_and_denies_send_without_upstream_request(): void
    {
        $provider = $this->provider('anthropic');
        $this->model($provider, 'public-disabled', 'private-disabled');
        $provider->update(['is_enabled' => false]);
        $user = User::factory()->create();
        $models = $this->actingAs($user)->getJson('/api/c/am')->assertOk()->json('models');
        $this->assertNotContains('public-disabled', array_column($models, 'id'));
        $this->postJson('/api/c/s', ['model' => 'public-disabled', 'messages' => [['role' => 'user', 'content' => 'Do not send']]])->assertForbidden();
        $this->get('/en/models')->assertOk()->assertDontSee('public-disabled');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'anthropic.example.test'));
    }

    public function test_stream_redacts_split_internal_names_without_corrupting_tool_arguments(): void
    {
        $provider = $this->provider('openai');
        $this->model($provider, 'public-safe', 'private-safe');
        $frames = [
            ['id' => 'chatcmpl-safe', 'model' => 'private-safe', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Via eno'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-safe', 'model' => 'private-safe', 'choices' => [['index' => 0, 'delta' => ['content' => 'wx safely.'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-safe', 'model' => 'private-safe', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"name":"eno'.'wx"}']]]], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-safe', 'model' => 'private-safe', 'choices' => [['index' => 0, 'delta' => new \stdClass, 'finish_reason' => 'tool_calls']]],
        ];
        $body = implode('', array_map(fn (array $frame): string => 'data: '.json_encode($frame)."\n\n", $frames))."data: [DONE]\n\n";
        Http::fake(['https://openai.example.test/v1/chat/completions' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);
        $events = iterator_to_array(app(AiProxyService::class)->streamChatCompletion([['role' => 'user', 'content' => 'Hello']], 'public-safe'));
        $content = implode('', array_map(fn (array $event): string => ((array) ($event['choices'][0]['delta'] ?? []))['content'] ?? '', $events));
        $this->assertSame('Via XSuper.ai safely.', $content);
        $tool = collect($events)->first(fn (array $event): bool => isset(((array) ($event['choices'][0]['delta'] ?? []))['tool_calls']));
        $this->assertSame('{"name":"eno'.'wx"}', $tool['choices'][0]['delta']['tool_calls'][0]['function']['arguments']);
    }

    public function test_streamed_api_settles_reported_usage_and_refunds_failed_provider_requests(): void
    {
        $provider = $this->provider('openai');
        $this->model($provider, 'public-billed-stream', 'private-billed-stream');
        $user = User::factory()->create(['role' => 'admin']);
        Wallet::credit($user->id, 1_000_000, 'Fixture balance');
        $key = ApiKey::generate($user->id);
        foreach (['input_tokens' => 1, 'output_tokens' => 2] as $meter => $price) {
            UsageRate::create(['service' => 'api', 'meter' => $meter, 'model' => 'public-billed-stream', 'label' => $meter, 'unit' => '1M tokens', 'price_usd' => $price, 'price_idr' => 16000 * $price, 'is_active' => true]);
        }
        $frames = [
            ['id' => 'chatcmpl-billed', 'model' => 'private-billed-stream', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Done'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-billed', 'model' => 'private-billed-stream', 'choices' => [['index' => 0, 'delta' => new \stdClass, 'finish_reason' => 'stop']]],
            ['id' => 'chatcmpl-billed', 'model' => 'private-billed-stream', 'choices' => [], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 6, 'total_tokens' => 18]],
        ];
        $body = implode('', array_map(fn (array $frame): string => 'data: '.json_encode($frame)."\n\n", $frames))."data: [DONE]\n\n";
        Http::fake(['https://openai.example.test/v1/chat/completions' => Http::sequence()
            ->push($body, 200, ['Content-Type' => 'text/event-stream'])
            ->push(['error' => ['message' => 'openai-fixture-key must not be forwarded']], 401)]);
        $payload = ['model' => 'public-billed-stream', 'stream' => true, 'max_completion_tokens' => 20, 'messages' => [['role' => 'user', 'content' => 'Hello']]];
        $stream = $this->withToken($key->plainKey)->postJson('/v1/chat/completions', $payload)->assertOk()->streamedContent();
        $this->assertSame(1, substr_count($stream, 'data: [DONE]'));
        $text = '';
        foreach (explode("\n", $stream) as $line) {
            if (str_starts_with($line, 'data: ') && $line !== 'data: [DONE]') {
                $event = json_decode(substr($line, 6), true);
                $text .= $event['choices'][0]['delta']['content'] ?? '';
            }
        }
        $this->assertSame('Done', $text);
        $this->assertSame(999976, Wallet::balance($user->id));
        $failed = $this->postJson('/v1/chat/completions', $payload)->assertOk()->streamedContent();
        $this->assertStringContainsString('"error"', $failed);
        $this->assertStringNotContainsString('openai-fixture-key', $failed);
        $this->assertSame(999976, Wallet::balance($user->id));
        $this->assertDatabaseCount('usage_logs', 1);
    }

    public function test_nonstream_provider_auth_failure_returns_safe_error_and_releases_reservation(): void
    {
        $provider = $this->provider('anthropic');
        $this->model($provider, 'public-failed', 'private-failed');
        $user = User::factory()->create(['role' => 'admin']);
        Wallet::credit($user->id, 1_000_000, 'Fixture balance');
        $key = ApiKey::generate($user->id);
        foreach (['input_tokens' => 1, 'output_tokens' => 2] as $meter => $price) {
            UsageRate::create(['service' => 'api', 'meter' => $meter, 'model' => 'public-failed', 'label' => $meter, 'unit' => '1M tokens', 'price_usd' => $price, 'price_idr' => 16000 * $price, 'is_active' => true]);
        }
        Http::fake(['https://anthropic.example.test/v1/messages' => Http::response([
            'type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'anthropic-fixture-key at https://anthropic.example.test is invalid'],
        ], 401)]);
        $response = $this->withToken($key->plainKey)->postJson('/v1/chat/completions', [
            'model' => 'public-failed', 'max_tokens' => 20, 'messages' => [['role' => 'user', 'content' => 'Hello']],
        ])->assertStatus(502);
        $this->assertStringNotContainsString('anthropic-fixture-key', $response->getContent());
        $this->assertStringNotContainsString('anthropic.example.test', $response->getContent());
        $this->assertSame(1_000_000, Wallet::balance($user->id));
        $this->assertDatabaseCount('usage_logs', 0);
    }

    private function provider(string $protocol): AiProviderProfile
    {
        return AiProviderProfile::create([
            'slug' => $protocol, 'name' => ucfirst($protocol).' connection', 'protocol' => $protocol,
            'base_url' => 'https://'.$protocol.'.example.test/v1', 'api_key' => $protocol.'-fixture-key',
            'api_version' => '2023-06-01', 'is_enabled' => true, 'status' => 'healthy',
        ]);
    }

    private function model(AiProviderProfile $provider, string $publicId, string $upstreamId): AiModelProfile
    {
        return AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => $publicId, 'upstream_model_id' => $upstreamId,
            'display_name' => $publicId, 'category' => 'chat', 'is_enabled' => true, 'is_available' => true,
        ]);
    }

    private function sse(array $events): string
    {
        return implode('', array_map(fn (array $event): string => 'event: '.$event['type']."\n".'data: '.json_encode($event)."\n\n", $events));
    }
}
