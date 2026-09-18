<?php

namespace Tests\Feature;

use App\Exceptions\AiProxyException;
use App\Models\AiProviderProfile;
use App\Services\AiProviderEndpoint;
use App\Services\AiProviderTransport;
use GuzzleHttp\Psr7\PumpStream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ProviderTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_openai_catalog_authenticates_and_preserves_provider_metadata(): void
    {
        $provider = $this->provider('openai', 'https://compatible.test/v1', 'openai-secret');
        Http::fake(['https://compatible.test/v1/models' => Http::response(['data' => [[
            'id' => 'vendor/model', 'name' => 'Vendor Model', 'owned_by' => 'vendor',
            'category' => 'chat', 'tier' => 'MAX', 'context_length' => 128000,
            'max_output_tokens' => 8192, 'capabilities' => ['tools', 'vision'],
        ]]])]);

        $models = $this->transport()->catalog($provider);

        $this->assertSame('vendor/model', $models[0]['id']);
        $this->assertSame('MAX', $models[0]['tier']);
        $this->assertSame(128000, $models[0]['context_length']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://compatible.test/v1/models'
            && $request->hasHeader('Authorization', 'Bearer openai-secret'));
    }

    public function test_anthropic_catalog_paginates_and_normalizes_metadata_without_generation(): void
    {
        $provider = $this->provider('anthropic', 'https://anthropic.test/v1', 'anthropic-secret');
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (parse_url($request->url(), PHP_URL_PATH) === '/v1/models' && ! isset($query['after_id'])) {
                return Http::response(['data' => [[
                    'id' => 'claude-first', 'display_name' => 'First', 'max_input_tokens' => 200000,
                    'max_tokens' => 8192, 'capabilities' => ['vision' => true, 'tools' => true],
                ]], 'has_more' => true, 'last_id' => 'claude-first']);
            }
            if (parse_url($request->url(), PHP_URL_PATH) === '/v1/models' && ($query['after_id'] ?? null) === 'claude-first') {
                return Http::response(['data' => [[
                    'id' => 'claude-second', 'display_name' => 'Second', 'max_input_tokens' => 100000,
                    'max_tokens' => 4096, 'capabilities' => ['tools' => true],
                ]], 'has_more' => false, 'last_id' => 'claude-second']);
            }

            return Http::response([], 500);
        });

        $models = $this->transport()->catalog($provider);

        $this->assertSame(['claude-first', 'claude-second'], array_column($models, 'id'));
        $this->assertSame(['text', 'image'], $models[0]['input_modalities']);
        $this->assertSame(8192, $models[0]['max_output_tokens']);
        $this->assertEqualsCanonicalizing(['tools', 'vision'], $models[0]['capabilities']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'anthropic-secret')
            && $request->hasHeader('anthropic-version', '2023-06-01'));
    }

    public function test_anthropic_completion_uses_native_messages_and_maps_canonical_response(): void
    {
        $provider = $this->provider('anthropic', 'https://anthropic.test/v1', 'anthropic-secret');
        Http::fake(['https://anthropic.test/v1/messages' => Http::response([
            'id' => 'msg_http', 'type' => 'message', 'role' => 'assistant', 'model' => 'native-claude',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_http', 'name' => 'lookup', 'input' => ['id' => 4]]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'cache_read_input_tokens' => 2, 'cache_creation_input_tokens' => 1, 'output_tokens' => 3],
        ])]);

        $result = $this->transport()->complete($provider, [
            'model' => 'native-claude', 'messages' => [['role' => 'user', 'content' => 'Lookup']],
            'tools' => [['type' => 'function', 'function' => ['name' => 'lookup', 'parameters' => ['type' => 'object']]]],
        ]);

        $this->assertSame('tool_calls', $result['choices'][0]['finish_reason']);
        $this->assertSame(16, $result['usage']['total_tokens']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://anthropic.test/v1/messages'
            && $request['model'] === 'native-claude' && $request['max_tokens'] === 4096
            && $request['tools'][0]['name'] === 'lookup' && ! isset($request['stream']));
    }

    public function test_openai_completion_forwards_compatible_options_without_model_heuristics(): void
    {
        $provider = $this->provider('openai', 'https://compatible.test/v1', 'secret');
        Http::fake(['https://compatible.test/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-http', 'object' => 'chat.completion', 'model' => 'vendor-gpt-name',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1, 'total_tokens' => 3],
        ])]);

        $result = $this->transport()->complete($provider, [
            'model' => 'vendor-gpt-name', 'messages' => [['role' => 'user', 'content' => 'hello']],
            'max_tokens' => 77, 'temperature' => 0, 'response_format' => ['type' => 'json_object'],
            'tools' => [['type' => 'function', 'function' => ['name' => 'echo', 'parameters' => ['type' => 'object']]]],
            'tool_choice' => 'auto', 'user' => 'member-1',
        ]);

        $this->assertSame('ok', $result['choices'][0]['message']['content']);
        Http::assertSent(fn (Request $request): bool => $request['model'] === 'vendor-gpt-name'
            && $request['max_tokens'] === 77 && $request['temperature'] === 0
            && $request['response_format']['type'] === 'json_object' && $request['user'] === 'member-1'
            && ! isset($request['max_completion_tokens']) && $request['stream'] === false);
    }

    public function test_openai_stream_requests_usage_and_parses_http_fake_psr_stream(): void
    {
        $provider = $this->provider('openai', 'https://compatible.test/v1', 'secret');
        $chunks = [
            "data: {\"id\":\"c\",\"model\":\"m\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"hel\"},\"finish_reason\":null}]}\n\n",
            "data: {\"id\":\"c\",\"model\":\"m\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"lo\"},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":2,\"completion_tokens\":1,\"total_tokens\":3}}\n\n",
            "data: [DONE]\n\n",
        ];
        $index = 0;
        Http::fake(['https://compatible.test/v1/chat/completions' => Http::response(new PumpStream(
            function () use (&$index, $chunks): ?string {
                return $chunks[$index++] ?? null;
            },
        ), 200, ['Content-Type' => 'text/event-stream'])]);

        $events = iterator_to_array($this->transport()->stream($provider, [
            'model' => 'm', 'messages' => [['role' => 'user', 'content' => 'hi']],
        ]), false);

        $this->assertSame('assistant', $events[0]['choices'][0]['delta']['role']);
        $this->assertSame('hel', $events[0]['choices'][0]['delta']['content']);
        $this->assertSame(3, $events[1]['usage']['total_tokens']);
        Http::assertSent(fn (Request $request): bool => $request['stream'] === true
            && $request['stream_options'] === ['include_usage' => true]);
    }

    public function test_anthropic_stream_maps_native_frames_to_canonical_events(): void
    {
        $provider = $this->provider('anthropic', 'https://anthropic.test/v1', 'secret');
        $body = implode('', [
            "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"msg\",\"model\":\"native\",\"usage\":{\"input_tokens\":5}}}\n\n",
            "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"hello\"}}\n\n",
            "event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":0}\n\n",
            "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":2}}\n\n",
            "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n",
        ]);
        Http::fake(['https://anthropic.test/v1/messages' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);

        $events = iterator_to_array($this->transport()->stream($provider, [
            'model' => 'native', 'messages' => [['role' => 'user', 'content' => 'hi']],
        ]), false);

        $this->assertSame('hello', $events[1]['choices'][0]['delta']['content']);
        $this->assertSame('stop', $events[2]['choices'][0]['finish_reason']);
        $this->assertSame(7, $events[2]['usage']['total_tokens']);
        Http::assertSent(fn (Request $request): bool => $request['stream'] === true
            && ! isset($request['stream_options']));
    }

    public function test_image_generation_is_openai_only_and_returns_raw_body(): void
    {
        $openAi = $this->provider('openai', 'https://compatible.test/v1', 'secret');
        Http::fake(['https://compatible.test/v1/images/generations' => Http::response([
            'created' => 123, 'data' => [['url' => 'https://cdn.test/image.png', 'revised_prompt' => 'Safe prompt']],
        ])]);

        $body = $this->transport()->imageGeneration($openAi, [
            'model' => 'image-model', 'prompt' => 'draw', 'size' => '1024x1024', 'n' => 1,
        ]);

        $this->assertSame(123, $body['created']);
        $this->assertSame('https://cdn.test/image.png', $body['data'][0]['url']);

        $this->expectException(AiProxyException::class);
        $this->expectExceptionMessage('not supported');
        $this->transport()->imageGeneration($this->provider('anthropic', 'https://anthropic.test/v1', 'secret'), [
            'model' => 'native', 'prompt' => 'draw',
        ]);
    }

    public function test_saved_stream_requires_a_pinned_destination_even_when_http_is_faked(): void
    {
        $provider = $this->provider('openai', 'https://compatible.test/v1', 'secret');
        Http::fake(['https://compatible.test/v1/chat/completions' => Http::response("data: [DONE]\n\n")]);
        $endpoint = Mockery::mock(AiProviderEndpoint::class);
        $endpoint->shouldReceive('normalize')->andReturn('https://compatible.test/v1');
        $endpoint->shouldReceive('requestOptions')->andReturn([
            'allow_redirects' => false, 'proxy' => '', 'verify' => true, 'curl' => [],
        ]);

        $this->expectException(AiProxyException::class);
        $this->expectExceptionMessage('unavailable');

        iterator_to_array((new AiProviderTransport($endpoint))->stream($provider, [
            'model' => 'm', 'messages' => [['role' => 'user', 'content' => 'hi']],
        ]));
    }

    public function test_missing_configuration_and_upstream_errors_are_sanitized(): void
    {
        config(['services.ai_proxy.url' => 'https://legacy-secret-host.test', 'services.ai_proxy.key' => '']);
        try {
            $this->transport()->catalog();
            $this->fail('Missing configuration should fail.');
        } catch (AiProxyException $exception) {
            $this->assertSame(503, $exception->responseStatus());
            $this->assertStringNotContainsString('legacy-secret-host', $exception->getMessage());
        }

        $provider = $this->provider('openai', 'https://compatible.test/v1', 'super-secret-key');
        Http::fake(['https://compatible.test/v1/chat/completions' => Http::response([
            'error' => ['message' => 'super-secret-key rejected at https://compatible.test/internal'],
        ], 401)]);

        try {
            $this->transport()->complete($provider, ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'hi']]]);
            $this->fail('Rejected request should fail.');
        } catch (AiProxyException $exception) {
            $this->assertSame(502, $exception->responseStatus());
            $this->assertStringNotContainsString('super-secret-key', $exception->getMessage());
            $this->assertStringNotContainsString('compatible.test', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    private function provider(string $protocol, string $baseUrl, string $key): AiProviderProfile
    {
        return AiProviderProfile::query()->create([
            'name' => ucfirst($protocol), 'slug' => $protocol.'-'.strtolower(bin2hex(random_bytes(3))),
            'protocol' => $protocol, 'base_url' => $baseUrl, 'api_key' => $key,
            'api_version' => '2023-06-01', 'is_enabled' => true,
        ]);
    }

    private function transport(): AiProviderTransport
    {
        $endpoint = Mockery::mock(AiProviderEndpoint::class);
        $endpoint->shouldReceive('normalize')->andReturnUsing(fn (string $url): string => rtrim($url, '/'));
        $endpoint->shouldReceive('requestOptions')->andReturnUsing(function (string $url): array {
            $host = (string) parse_url($url, PHP_URL_HOST);

            return [
                'allow_redirects' => false,
                'proxy' => '',
                'verify' => true,
                'curl' => [CURLOPT_RESOLVE => [$host.':443:203.0.113.10']],
            ];
        });

        return new AiProviderTransport($endpoint);
    }
}
