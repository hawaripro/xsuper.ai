<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\ApiFixture;
use Tests\TestCase;

class AnthropicApiCompatibilityTest extends TestCase
{
    use RefreshDatabase, ApiFixture;

    public function test_native_messages_preserve_tool_result_cache_control_and_thinking_signatures(): void
    {
        [, $key] = $this->apiFixture('anthropic');
        $payload = ['model' => 'public-model', 'max_tokens' => 100,
            'system' => [['type' => 'text', 'text' => "  Client instructions\n", 'cache_control' => ['type' => 'ephemeral']]],
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 50],
            'messages' => [
                ['role' => 'user', 'content' => 'Read a file'],
                ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => 'Plan', 'signature' => 'signed-thought'], ['type' => 'tool_use', 'id' => 'tool_1', 'name' => 'read', 'input' => new \stdClass]]],
                ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'tool_1', 'content' => '', 'is_error' => false]]],
            ], 'tools' => [['name' => 'read', 'input_schema' => ['type' => 'object']]], 'tool_choice' => ['type' => 'auto']];
        Http::fake(['*' => Http::response(['id' => 'msg_native', 'type' => 'message', 'role' => 'assistant', 'model' => 'private-model',
            'content' => [['type' => 'text', 'text' => 'Done'], ['type' => 'tool_use', 'id' => 'tool_next', 'name' => 'read', 'input' => new \stdClass]], 'stop_reason' => 'tool_use', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'provider_cost' => 99], 'provider' => 'upstream-secret'])]);
        $response = $this->withToken($key->plainKey)->postJson('/v1/messages', $payload)->assertOk();
        $response->assertJsonPath('model', 'public-model')->assertJsonPath('content.0.text', 'Done')->assertDontSee('private-model')->assertDontSee('provider')->assertDontSee('upstream-secret');
        $this->assertInstanceOf(\stdClass::class, json_decode($response->getContent())->content[1]->input);
        Http::assertSent(function ($request) use ($payload): bool {
            $this->assertEquals(json_decode(json_encode([...$payload, 'model' => 'private-model'])), json_decode($request->body()));
            return true;
        });
    }

    public function test_openai_coding_request_preserves_client_system_and_tool_messages(): void
    {
        [, $key] = $this->apiFixture();
        Http::fake(['*' => Http::response($this->openAiAnswer())]);
        $messages = [['role' => 'developer', 'content' => "  Client-owned rules\n"],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{}']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '']];
        $this->withToken($key->plainKey)->postJson('/v1/chat/completions', ['model' => 'public-model', 'messages' => $messages, 'max_completion_tokens' => 32,
            'tools' => [['type' => 'function', 'function' => ['name' => 'read', 'parameters' => ['type' => 'object']]]], 'tool_choice' => 'auto'])->assertOk();
        Http::assertSent(function ($request) use ($messages): bool {
            $this->assertEquals($messages, $request['messages']);
            return $request['max_completion_tokens'] === 32 && $request['tool_choice'] === 'auto';
        });
    }

    public function test_fal_tools_are_rejected_before_billing_or_network(): void
    {
        [$user, $key, , $provider] = $this->apiFixture();
        $provider->update(['protocol' => 'fal', 'base_url' => 'https://fal.run']);
        $this->withToken($key->plainKey)->postJson('/v1/messages', ['model' => 'public-model', 'messages' => [['role' => 'user', 'content' => 'Read']],
            'tools' => [['name' => 'read', 'input_schema' => ['type' => 'object']]]])->assertStatus(400)->assertJsonPath('type', 'error')->assertJsonPath('error.type', 'invalid_request_error');
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $user->id, 'type' => 'reserve']);
        Http::assertNothingSent();
    }
}
