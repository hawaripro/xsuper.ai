<?php

namespace Tests\Feature\Api;

use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicMessagesBridgeTest extends TestCase
{
    use RefreshDatabase, ApiFixture;

    public function test_tools_images_system_and_results_round_trip_without_injected_prompts(): void
    {
        [$user, $key] = $this->apiFixture();
        $answer = $this->openAiAnswer(['prompt_tokens' => 100, 'completion_tokens' => 5, 'prompt_tokens_details' => ['cached_tokens' => 80]]);
        $answer['choices'][0] = ['index' => 0, 'finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => 'Checking',
            'tool_calls' => [['id' => 'call_read', 'type' => 'function', 'function' => ['name' => 'read_file', 'arguments' => '{"path":"src/main.php"}']]]]];
        Http::fake(['https://provider.example.test/v1/chat/completions' => Http::sequence()->push($answer)->push($this->openAiAnswer())]);
        $payload = ['model' => 'public-model', 'max_tokens' => 32, 'system' => [['type' => 'text', 'text' => 'Client rules', 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Inspect'], ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'aGVsbG8=']], ['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://example.org/image.png']]]]],
            'tools' => [['name' => 'read_file', 'description' => 'Read a file', 'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]]],
            'tool_choice' => ['type' => 'any'], 'top_k' => 10, 'top_p' => 0.8, 'temperature' => 0.2, 'stop_sequences' => ['STOP'], 'metadata' => ['user_id' => 'coding-user']];
        $response = $this->withToken($key->plainKey)->postJson('/v1/messages', $payload)->assertOk();
        $response->assertJsonPath('content.1.type', 'tool_use')->assertJsonPath('content.1.input.path', 'src/main.php')
            ->assertJsonPath('stop_reason', 'tool_use')->assertJsonPath('usage.input_tokens', 20)->assertJsonPath('usage.cache_read_input_tokens', 80);
        $this->assertSame(999900, Wallet::balance($user->id));
        $this->assertDatabaseHas('usage_logs', ['api_key_id' => $key->id, 'prompt_tokens' => 100, 'cost_microusd' => 100]);
        Http::assertSent(function ($request): bool {
            $this->assertSame([
                'max_tokens' => 32, 'top_p' => 0.8, 'temperature' => 0.2, 'stop' => ['STOP'], 'user' => 'coding-user',
                'tools' => [['type' => 'function', 'function' => ['name' => 'read_file', 'description' => 'Read a file', 'parameters' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]]]],
                'tool_choice' => 'required', 'model' => 'private-model', 'messages' => [
                    ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'Client rules']]],
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Inspect'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,aGVsbG8=']], ['type' => 'image_url', 'image_url' => ['url' => 'https://example.org/image.png']]]],
                ], 'stream' => false,
            ], $request->data());
            return true;
        });
        $payload['messages'][] = ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => 'private thought'], ...$response->json('content')]];
        $payload['messages'][] = ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'call_read', 'content' => [['type' => 'text', 'text' => '<?php echo 1;']]]]];
        $this->postJson('/v1/messages', $payload)->assertOk();
        Http::assertSent(fn ($request) => ($request['messages'][3] ?? null) === ['role' => 'tool', 'tool_call_id' => 'call_read', 'content' => [['type' => 'text', 'text' => '<?php echo 1;']]]
            && $request['messages'][2]['tool_calls'][0]['function']['arguments'] === '{"path":"src/main.php"}');
    }

    public function test_stream_preserves_tool_argument_fragments_and_anthropic_order(): void
    {
        [, $key] = $this->apiFixture();
        $events = [
            ['choices' => [['delta' => ['content' => 'Checking '], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['content' => 'files.'], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'read_file', 'arguments' => '']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"path":']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"a.php"}']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => new \stdClass, 'finish_reason' => 'tool_calls']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8]],
        ];
        Http::fake(['*' => Http::response($this->eventStream($events, true), 200, ['Content-Type' => 'text/event-stream'])]);
        $stream = $this->withToken($key->plainKey)->postJson('/v1/messages', ['model' => 'public-model', 'stream' => true, 'max_tokens' => 20, 'messages' => [['role' => 'user', 'content' => 'Read files']]])->assertOk()->streamedContent();
        $frames = $this->parseEvents($stream);
        $this->assertSame(['message_start', 'content_block_start', 'content_block_delta', 'content_block_delta', 'content_block_stop', 'content_block_start', 'content_block_delta', 'content_block_delta', 'content_block_stop', 'message_delta', 'message_stop'], array_column($frames, 'type'));
        $this->assertGreaterThan(0, $frames[0]['message']['usage']['input_tokens']);
        $this->assertSame(['type' => 'tool_use', 'id' => 'call_1', 'name' => 'read_file', 'input' => []], $frames[5]['content_block']);
        $this->assertSame('{"path":"a.php"}', $frames[6]['delta']['partial_json'].$frames[7]['delta']['partial_json']);
        $this->assertSame('tool_use', $frames[9]['delta']['stop_reason']);
        $this->assertSame(8, $frames[9]['usage']['output_tokens']);
        Http::assertSent(fn ($request) => $request['stream_options']['include_usage'] === true);
    }

    public function test_stream_tool_arguments_of_zero_are_invalid_input_not_an_empty_object(): void
    {
        [$user, $key] = $this->apiFixture();
        $events = [
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'read_file', 'arguments' => '0']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => new \stdClass, 'finish_reason' => 'tool_calls']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8]],
        ];
        Http::fake(['*' => Http::response($this->eventStream($events, true), 200, ['Content-Type' => 'text/event-stream'])]);
        $stream = $this->withToken($key->plainKey)->postJson('/v1/messages', ['model' => 'public-model', 'stream' => true, 'max_tokens' => 20, 'messages' => [['role' => 'user', 'content' => 'Read files']]])->assertOk()->streamedContent();
        $this->assertStringContainsString('"partial_json":"0"', $stream);
        $this->assertStringContainsString('event: error', $stream);
        $this->assertStringNotContainsString('event: message_stop', $stream);
        // The delivered tool call keeps its reservation for review: neither settled nor released.
        $this->assertLessThan(1_000_000, Wallet::balance($user->id));
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $user->id, 'type' => 'settlement']);
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $user->id, 'type' => 'release']);
    }

    public function test_native_anthropic_body_is_preserved_and_sse_cache_usage_is_billed(): void
    {
        [$user, $key] = $this->apiFixture('anthropic');
        $payload = ['model' => 'public-model', 'max_tokens' => 32, 'stream' => true, 'top_k' => 4,
            'system' => [['type' => 'text', 'text' => 'Client only', 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => 'Hello']], 'tools' => [['name' => 'read', 'input_schema' => ['type' => 'object']]], 'tool_choice' => ['type' => 'none']];
        $events = [
            ['type' => 'message_start', 'message' => ['id' => 'msg_native', 'type' => 'message', 'role' => 'assistant', 'model' => 'private-model', 'content' => [], 'usage' => ['input_tokens' => 10, 'cache_read_input_tokens' => 80, 'cache_creation_input_tokens' => 10, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 5]],
            ['type' => 'message_stop'],
        ];
        Http::fake(['*' => Http::response($this->eventStream($events), 200, ['Content-Type' => 'text/event-stream'])]);
        $stream = $this->withHeaders(['x-api-key' => $key->plainKey, 'anthropic-version' => '2023-06-01', 'anthropic-beta' => 'prompt-caching-2024-07-31'])->postJson('/v1/messages', $payload)->assertOk()->streamedContent();
        $events[0]['message']['model'] = 'public-model';
        $this->assertSame($events, $this->parseEvents($stream));
        $this->assertStringNotContainsString('private-model', $stream);
        $this->assertSame(999890, Wallet::balance($user->id));
        Http::assertSent(fn ($request) => $request->data() === [...$payload, 'model' => 'private-model'] && $request->hasHeader('anthropic-beta', 'prompt-caching-2024-07-31'));
    }

    public function test_count_tokens_is_free_and_insufficient_wallet_uses_anthropic_envelope(): void
    {
        [$user, $key] = $this->apiFixture();
        $payload = ['model' => 'public-model', 'messages' => [['role' => 'user', 'content' => 'abc']]];
        $this->withToken($key->plainKey)->postJson('/v1/messages/count_tokens', $payload)->assertOk()->assertJsonPath('input_tokens', 5);
        $this->assertSame(1_000_000, Wallet::balance($user->id));
        Wallet::where('user_id', $user->id)->update(['balance_microusd' => 0]);
        $this->postJson('/v1/messages', $payload)->assertStatus(402)->assertJsonPath('type', 'error')->assertJsonPath('error.type', 'billing_error');
        Http::assertNothingSent();
    }

    public function test_midstream_failure_is_safe_and_only_releases_before_output(): void
    {
        [$user, $key] = $this->apiFixture();
        Http::fake(['*' => Http::sequence()->push(['error' => ['message' => 'upstream-secret']], 500)
            ->push('data: '.json_encode(['choices' => [['delta' => ['content' => 'Partial'], 'finish_reason' => null]]])."\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
        $payload = ['model' => 'public-model', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'Hi']]];
        $first = $this->withToken($key->plainKey)->postJson('/v1/messages', $payload)->assertOk()->streamedContent();
        $this->assertStringContainsString('event: error', $first);
        $this->assertStringNotContainsString('upstream-secret', $first);
        $this->assertSame(1_000_000, Wallet::balance($user->id));
        $second = $this->postJson('/v1/messages', $payload)->assertOk()->streamedContent();
        $this->assertStringContainsString('Partial', $second);
        $this->assertStringContainsString('event: error', $second);
        $this->assertLessThan(1_000_000, Wallet::balance($user->id));
    }

    public function test_empty_tool_objects_results_and_metadata_remain_valid_for_coding_clients(): void
    {
        [, $key] = $this->apiFixture();
        Http::fake(['*' => Http::response($this->openAiAnswer())]);
        $this->withToken($key->plainKey)->postJson('/v1/messages', [
            'model' => 'public-model', 'metadata' => new \stdClass,
            'tools' => [['name' => 'status', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass]]],
            'tool_choice' => ['type' => 'tool', 'name' => 'status'],
            'messages' => [
                ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'call_empty', 'name' => 'status', 'input' => new \stdClass]]],
                ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'call_empty', 'content' => '']]],
            ],
        ])->assertOk();
        Http::assertSent(function ($request): bool {
            $wire = json_decode($request->body());
            $this->assertInstanceOf(\stdClass::class, $wire->tools[0]->function->parameters->properties);
            $this->assertSame('{}', $wire->messages[0]->tool_calls[0]->function->arguments);
            $this->assertSame('', $wire->messages[1]->content);
            $this->assertSame('call_empty', $wire->messages[1]->tool_call_id);
            $this->assertEquals(['type' => 'function', 'function' => ['name' => 'status']], $request['tool_choice']);
            $this->assertArrayNotHasKey('user', $request->data());
            return true;
        });
    }

    private function parseEvents(string $stream): array
    {
        $events = [];
        foreach (explode("\n", $stream) as $line) { if (str_starts_with($line, 'data: ')) { $events[] = json_decode(substr($line, 6), true); } }
        return $events;
    }
}
