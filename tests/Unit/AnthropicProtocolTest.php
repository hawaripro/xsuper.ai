<?php

namespace Tests\Unit;

use App\Exceptions\AiProxyException;
use App\Services\AnthropicProtocol;
use App\Services\ProviderSseStream;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use stdClass;

class AnthropicProtocolTest extends TestCase
{
    public function test_converts_system_messages_and_preserves_client_tool_round_trips(): void
    {
        $request = AnthropicProtocol::request([
            'model' => 'native-model',
            'messages' => [
                ['role' => 'system', 'content' => 'System instruction'],
                ['role' => 'developer', 'content' => [['type' => 'text', 'text' => 'Developer instruction']]],
                ['role' => 'user', 'content' => 'Weather?'],
                ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                    ['id' => 'call-original', 'type' => 'function', 'function' => [
                        'name' => 'weather', 'arguments' => '{"city":"Jakarta","options":{}}',
                    ]],
                ]],
                ['role' => 'tool', 'tool_call_id' => 'call-original', 'content' => 'Sunny'],
                ['role' => 'user', 'content' => 'Summarize that.'],
            ],
            'tools' => [['type' => 'function', 'function' => [
                'name' => 'weather', 'description' => 'Look up weather',
                'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            ]]],
            'tool_choice' => ['type' => 'function', 'function' => ['name' => 'weather']],
            'max_tokens' => 120,
            'stop' => ['STOP'],
            'temperature' => 0,
        ]);

        $this->assertSame([
            ['type' => 'text', 'text' => 'System instruction'],
            ['type' => 'text', 'text' => 'Developer instruction'],
        ], $request['system']);
        $expectedMessages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Weather?']]],
            ['role' => 'assistant', 'content' => [[
                'type' => 'tool_use', 'id' => 'call-original', 'name' => 'weather',
                'input' => json_decode('{"city":"Jakarta","options":{}}'),
            ]]],
            ['role' => 'user', 'content' => [[
                'type' => 'tool_result', 'tool_use_id' => 'call-original', 'content' => 'Sunny',
            ], ['type' => 'text', 'text' => 'Summarize that.']]],
        ];
        $this->assertEquals($expectedMessages, $request['messages']);
        $this->assertSame('weather', $request['tools'][0]['name']);
        $this->assertSame(['type' => 'tool', 'name' => 'weather'], $request['tool_choice']);
        $this->assertSame(['STOP'], $request['stop_sequences']);
        $this->assertSame(0, $request['temperature']);
        $this->assertArrayNotHasKey('stream', $request);
    }

    public function test_converts_inline_and_remote_images_and_pdf_documents(): void
    {
        $request = AnthropicProtocol::request([
            'model' => 'native-model',
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Inspect these'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']],
                ['type' => 'image_url', 'image_url' => ['url' => 'https://cdn.example.test/photo.png']],
                ['type' => 'file', 'file' => ['file_data' => 'data:application/pdf;base64,JVBERi0xLjQ=', 'filename' => 'brief.pdf']],
            ]]],
        ]);

        $this->assertSame([
            ['type' => 'text', 'text' => 'Inspect these'],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'iVBORw0KGgo=']],
            ['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://cdn.example.test/photo.png']],
            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'JVBERi0xLjQ='], 'title' => 'brief.pdf'],
        ], $request['messages'][0]['content']);
    }

    #[DataProvider('invalidPayloads')]
    public function test_rejects_options_and_content_it_cannot_translate(array $payload): void
    {
        $this->expectException(AiProxyException::class);
        $this->expectExceptionMessage('not supported');

        AnthropicProtocol::request(array_replace([
            'model' => 'native-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ], $payload));
    }

    public static function invalidPayloads(): array
    {
        return [
            'non-HTTPS image URL' => [['messages' => [['role' => 'user', 'content' => [[
                'type' => 'image_url', 'image_url' => ['url' => 'ftp://private.test/photo.png'],
            ]]]]]],
            'unknown option' => [['frequency_penalty' => 0.3]],
            'unknown content part' => [['messages' => [['role' => 'user', 'content' => [[
                'type' => 'input_audio', 'input_audio' => ['data' => 'abc', 'format' => 'wav'],
            ]]]]]],
            'non-object tool input' => [['messages' => [['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                'id' => 'bad', 'type' => 'function', 'function' => ['name' => 'bad', 'arguments' => '[1,2]'],
            ]]]]]],
        ];
    }

    public function test_maps_text_tools_finish_reason_and_exact_cache_usage(): void
    {
        $result = AnthropicProtocol::response([
            'id' => 'msg_123', 'type' => 'message', 'model' => 'native-model',
            'content' => [
                ['type' => 'text', 'text' => 'Let me check.'],
                ['type' => 'thinking', 'thinking' => 'internal detail', 'signature' => 'sig'],
                ['type' => 'redacted_thinking', 'data' => 'opaque'],
                ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'weather', 'input' => ['city' => 'Jakarta']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => [
                'input_tokens' => 7,
                'cache_read_input_tokens' => 2,
                'cache_creation_input_tokens' => 3,
                'output_tokens' => 5,
            ],
        ]);

        $this->assertSame('Let me check.', $result['choices'][0]['message']['content']);
        $this->assertSame('tool_calls', $result['choices'][0]['finish_reason']);
        $this->assertSame('toolu_123', $result['choices'][0]['message']['tool_calls'][0]['id']);
        $this->assertSame('{"city":"Jakarta"}', $result['choices'][0]['message']['tool_calls'][0]['function']['arguments']);
        $this->assertSame([
            'prompt_tokens' => 12,
            'completion_tokens' => 5,
            'total_tokens' => 17,
            'prompt_tokens_details' => ['cached_tokens' => 2, 'cache_creation_tokens' => 3],
        ], $result['usage']);
    }

    public function test_parses_fragmented_utf8_crlf_multiline_frames(): void
    {
        $json = json_encode(['choices' => [[
            'index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Halo dunia 🌏'], 'finish_reason' => 'stop',
        ]], 'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2, 'total_tokens' => 6]], JSON_UNESCAPED_UNICODE);
        $splitAt = strpos($json, '🌏') + 2;
        $chunks = [
            "event: chunk\r\ndata: ".substr($json, 0, $splitAt),
            substr($json, $splitAt)."\r\n\r",
            "\ndata: [DONE]\r\n\r\n",
        ];

        $events = iterator_to_array(ProviderSseStream::openAi($this->fragmentedStream($chunks)), false);

        $this->assertCount(1, $events);
        $this->assertSame('assistant', $events[0]['choices'][0]['delta']['role']);
        $this->assertSame('Halo dunia 🌏', $events[0]['choices'][0]['delta']['content']);
        $this->assertSame(6, $events[0]['usage']['total_tokens']);
    }

    public function test_maps_fragmented_anthropic_tool_stream_and_final_usage(): void
    {
        $frames = [
            ['message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_s', 'model' => 'native-model', 'usage' => ['input_tokens' => 8, 'cache_read_input_tokens' => 2, 'cache_creation_input_tokens' => 1]]]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hi']]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_s', 'name' => 'weather', 'input' => new stdClass]]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"city":']]],
            ['content_block_stop', ['type' => 'content_block_stop', 'index' => 0]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '"Bandung"}']]],
            ['content_block_stop', ['type' => 'content_block_stop', 'index' => 1]],
            ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 4]]],
            ['message_stop', ['type' => 'message_stop']],
        ];
        $body = '';
        foreach ($frames as [$event, $data]) {
            $body .= "event: {$event}\r\ndata: ".json_encode($data)."\r\n\r\n";
        }
        $chunks = str_split($body, 7);

        $events = iterator_to_array(ProviderSseStream::anthropic($this->fragmentedStream($chunks)), false);
        $this->assertSame('assistant', $events[0]['choices'][0]['delta']['role']);
        $this->assertSame('Hi', $events[1]['choices'][0]['delta']['content']);
        $toolStart = $events[2]['choices'][0]['delta']['tool_calls'][0];
        $this->assertSame(['index' => 0, 'id' => 'toolu_s', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '']], $toolStart);
        $this->assertSame('{"city":', $events[3]['choices'][0]['delta']['tool_calls'][0]['function']['arguments']);
        $this->assertSame('tool_calls', $events[5]['choices'][0]['finish_reason']);
        $this->assertSame(15, $events[5]['usage']['total_tokens']);
    }

    #[DataProvider('incompleteStreams')]
    public function test_incomplete_and_error_streams_cannot_masquerade_as_success(callable $stream): void
    {
        $this->expectException(AiProxyException::class);
        $this->expectExceptionMessage('stream');

        iterator_to_array($stream(), false);
    }

    public static function incompleteStreams(): array
    {
        return [
            'openai early eof' => [fn () => ProviderSseStream::openAi(Utils::streamFor(
                "data: {\"choices\":[{\"delta\":{\"content\":\"partial\"},\"finish_reason\":null}]}\n\n",
            ))],
            'openai done without finish reason' => [fn () => ProviderSseStream::openAi(Utils::streamFor("data: [DONE]\n\n"))],
            'anthropic early eof' => [fn () => ProviderSseStream::anthropic(Utils::streamFor(
                "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"m\",\"model\":\"x\",\"usage\":{}}}\n\n",
            ))],
            'provider error' => [fn () => ProviderSseStream::anthropic(Utils::streamFor(
                "event: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"secret raw error\"}}\n\n",
            ))],
        ];
    }

    private function fragmentedStream(array $chunks): StreamInterface
    {
        return new class(Utils::streamFor(''), $chunks) implements StreamInterface
        {
            use StreamDecoratorTrait;

            private StreamInterface $stream;

            private int $offset = 0;

            public function __construct(StreamInterface $stream, private readonly array $chunks)
            {
                $this->stream = $stream;
            }

            public function eof(): bool
            {
                return $this->offset >= count($this->chunks);
            }

            public function read($length): string
            {
                return $this->chunks[$this->offset++] ?? '';
            }
        };
    }
}
