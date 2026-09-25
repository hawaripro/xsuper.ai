<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use Generator;
use JsonException;
use Psr\Http\Message\StreamInterface;

final class ProviderSseStream
{
    /**
     * @return Generator<int, array<string, mixed>>
     */
    public static function openAi(StreamInterface $body): Generator
    {
        $terminated = false;
        $finished = false;

        foreach (self::frames($body) as $frame) {
            $data = $frame['data'];
            if ($frame['event'] === 'error') {
                throw self::streamFailure();
            }
            if (trim($data) === '[DONE]') {
                $terminated = true;
                break;
            }

            $event = self::decode($data);
            if (isset($event['error'])) {
                throw self::streamFailure();
            }
            if (! isset($event['choices']) || ! is_array($event['choices'])) {
                throw self::invalidStream();
            }

            foreach ($event['choices'] as $choice) {
                if (is_array($choice) && is_string($choice['finish_reason'] ?? null) && $choice['finish_reason'] !== '') {
                    $finished = true;
                }
            }

            yield $event;
        }

        if (! $terminated || ! $finished) {
            throw self::incompleteStream();
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public static function anthropic(StreamInterface $body): Generator
    {
        $id = null;
        $model = null;
        $inputUsage = [];
        $toolIndexes = [];
        $toolArguments = [];
        $openBlocks = [];
        $nextToolIndex = 0;
        $finished = false;
        $terminated = false;

        foreach (self::frames($body) as $frame) {
            $event = self::decode($frame['data']);
            $type = is_string($event['type'] ?? null) ? $event['type'] : $frame['event'];

            switch ($type) {
                case 'ping':
                    break;

                case 'error':
                    throw self::streamFailure();
                case 'message_start':
                    $message = is_array($event['message'] ?? null) ? $event['message'] : [];
                    $id = self::requiredString($message, 'id');
                    $model = self::requiredString($message, 'model');
                    $inputUsage = is_array($message['usage'] ?? null) ? $message['usage'] : [];
                    yield self::chunk($id, $model, ['role' => 'assistant', 'content' => '']);
                    break;

                case 'content_block_start':
                    self::assertStarted($id, $model);
                    $blockIndex = self::integerIndex($event['index'] ?? null);
                    if (isset($openBlocks[$blockIndex])) {
                        throw self::invalidStream();
                    }
                    $block = is_array($event['content_block'] ?? null) ? $event['content_block'] : [];
                    if (($block['type'] ?? null) === 'text') {
                        $text = $block['text'] ?? '';
                        if (! is_string($text)) {
                            throw self::invalidStream();
                        }
                        $openBlocks[$blockIndex] = 'text';
                        if ($text !== '') {
                            yield self::chunk($id, $model, ['content' => $text]);
                        }
                        break;
                    }
                    if (($block['type'] ?? null) === 'tool_use') {
                        $toolIndex = $nextToolIndex++;
                        $toolIndexes[$blockIndex] = $toolIndex;
                        $toolArguments[$blockIndex] = self::initialToolArguments($block['input'] ?? []);
                        $openBlocks[$blockIndex] = 'tool_use';
                        yield self::chunk($id, $model, ['tool_calls' => [[
                            'index' => $toolIndex,
                            'id' => self::requiredString($block, 'id'),
                            'type' => 'function',
                            'function' => [
                                'name' => self::requiredString($block, 'name'),
                                'arguments' => $toolArguments[$blockIndex],
                            ],
                        ]]]);
                        break;
                    }
                    if (in_array($block['type'] ?? null, ['thinking', 'redacted_thinking'], true)) {
                        $openBlocks[$blockIndex] = $block['type'];
                        break;
                    }

                    throw self::invalidStream();
                case 'content_block_delta':
                    self::assertStarted($id, $model);
                    $delta = is_array($event['delta'] ?? null) ? $event['delta'] : [];
                    if (($delta['type'] ?? null) === 'text_delta') {
                        $blockIndex = self::integerIndex($event['index'] ?? null);
                        if (($openBlocks[$blockIndex] ?? null) !== 'text' || ! is_string($delta['text'] ?? null)) {
                            throw self::invalidStream();
                        }
                        yield self::chunk($id, $model, ['content' => $delta['text']]);
                        break;
                    }
                    if (($delta['type'] ?? null) === 'input_json_delta') {
                        $blockIndex = self::integerIndex($event['index'] ?? null);
                        if (($openBlocks[$blockIndex] ?? null) !== 'tool_use'
                            || ! isset($toolIndexes[$blockIndex]) || ! is_string($delta['partial_json'] ?? null)) {
                            throw self::invalidStream();
                        }
                        $toolArguments[$blockIndex] .= $delta['partial_json'];
                        yield self::chunk($id, $model, ['tool_calls' => [[
                            'index' => $toolIndexes[$blockIndex],
                            'function' => ['arguments' => $delta['partial_json']],
                        ]]]);
                        break;
                    }
                    if (($delta['type'] ?? null) === 'thinking_delta') {
                        $blockIndex = self::integerIndex($event['index'] ?? null);
                        if (($openBlocks[$blockIndex] ?? null) !== 'thinking') {
                            throw self::invalidStream();
                        }
                        break;
                    }
                    if (($delta['type'] ?? null) === 'signature_delta') {
                        $blockIndex = self::integerIndex($event['index'] ?? null);
                        if (($openBlocks[$blockIndex] ?? null) !== 'thinking') {
                            throw self::invalidStream();
                        }
                        break;
                    }

                    throw self::invalidStream();
                case 'content_block_stop':
                    $blockIndex = self::integerIndex($event['index'] ?? null);
                    if (! isset($openBlocks[$blockIndex])) {
                        throw self::invalidStream();
                    }
                    if ($openBlocks[$blockIndex] === 'tool_use') {
                        $arguments = $toolArguments[$blockIndex];
                        if ($arguments === '') {
                            $arguments = '{}';
                            yield self::chunk($id, $model, ['tool_calls' => [[
                                'index' => $toolIndexes[$blockIndex],
                                'function' => ['arguments' => $arguments],
                            ]]]);
                        }
                        self::validateToolArguments($arguments);
                    }
                    unset($openBlocks[$blockIndex]);
                    break;

                case 'message_delta':
                    if ($openBlocks !== [] || $finished) {
                        throw self::invalidStream();
                    }
                    self::assertStarted($id, $model);
                    $delta = is_array($event['delta'] ?? null) ? $event['delta'] : [];
                    $reason = $delta['stop_reason'] ?? null;
                    if (! is_string($reason) || $reason === '') {
                        throw self::invalidStream();
                    }
                    $output = is_array($event['usage'] ?? null) ? $event['usage'] : [];
                    $outputTokens = $output['output_tokens'] ?? null;
                    $finished = true;
                    $chunk = self::chunk($id, $model, [], AnthropicProtocol::finishReason($reason));
                    $chunk['usage'] = AnthropicProtocol::usage(array_replace($inputUsage, ['output_tokens' => $outputTokens]));
                    yield $chunk;
                    break;

                case 'message_stop':
                    if ($openBlocks !== [] || ! $finished) {
                        throw self::invalidStream();
                    }
                    $terminated = true;
                    break 2;

                default:
                    throw self::invalidStream();
            }
        }

        if (! $terminated || ! $finished) {
            throw self::incompleteStream();
        }
    }

    /**
     * @return Generator<int, array{event: string, data: string}>
     */
    private static function frames(StreamInterface $body): Generator
    {
        $buffer = '';
        foreach (self::chunks($body) as $chunk) {
            $buffer .= $chunk;

            while (preg_match('/\r\n\r\n|\n\n|\r\r/', $buffer, $match, PREG_OFFSET_CAPTURE) === 1) {
                $position = $match[0][1];
                $delimiterLength = strlen($match[0][0]);
                $rawFrame = substr($buffer, 0, $position);
                $buffer = substr($buffer, $position + $delimiterLength);
                $parsed = self::frame($rawFrame);
                if ($parsed !== null) {
                    yield $parsed;
                }
            }
        }

        if (trim($buffer) !== '') {
            throw self::incompleteStream();
        }
    }

    private static function chunks(StreamInterface $body): Generator
    {
        // PHP's blocking HTTPS wrapper otherwise waits for a full read buffer.
        if ($body->getMetadata('wrapper_type') === 'http') {
            $resource = $body->detach();
            if (! is_resource($resource)) {
                throw self::streamFailure();
            }
            try {
                if (! stream_set_blocking($resource, false)) {
                    throw self::streamFailure();
                }
                $deadline = microtime(true) + 120;
                while (! feof($resource)) {
                    if (microtime(true) >= $deadline) {
                        throw self::streamFailure();
                    }
                    $chunk = fread($resource, 8192);
                    if ($chunk === false) {
                        throw self::streamFailure();
                    }
                    if ($chunk !== '') {
                        yield $chunk;

                        continue;
                    }
                    if (feof($resource)) {
                        break;
                    }
                    // Filtered HTTPS streams cannot be selected on Windows. Wait
                    // for one byte, then return to draining available bytes.
                    $remaining = max(0.0, $deadline - microtime(true));
                    $seconds = (int) $remaining;
                    $microseconds = (int) (($remaining - $seconds) * 1_000_000);
                    stream_set_chunk_size($resource, 1);
                    stream_set_blocking($resource, true);
                    stream_set_timeout($resource, $seconds, $microseconds);
                    $first = fread($resource, 1);
                    $metadata = stream_get_meta_data($resource);
                    stream_set_blocking($resource, false);
                    stream_set_chunk_size($resource, 8192);
                    if ($first === false || ! empty($metadata['timed_out'])) {
                        throw self::streamFailure();
                    }
                    if ($first === '') {
                        if (feof($resource)) {
                            break;
                        }
                        throw self::incompleteStream();
                    }
                    yield $first;
                }
            } finally {
                fclose($resource);
            }

            return;
        }

        while (! $body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                if ($body->eof()) {
                    return;
                }
                throw self::streamFailure();
            }
            yield $chunk;
        }
    }

    /**
     * @return array{event: string, data: string}|null
     */
    private static function frame(string $raw): ?array
    {
        $event = 'message';
        $data = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }
            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }
            if ($field === 'event') {
                $event = $value;
            } elseif ($field === 'data') {
                $data[] = $value;
            }
        }

        if ($data === []) {
            return null;
        }

        return ['event' => $event, 'data' => implode("\n", $data)];
    }

    /** @return array<string, mixed> */
    private static function decode(string $data): array
    {
        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::invalidStream();
        }

        if (! is_array($decoded)) {
            throw self::invalidStream();
        }

        return $decoded;
    }

    /** @param array<string, mixed> $delta */
    private static function chunk(string $id, string $model, array $delta, ?string $finishReason = null): array
    {
        return [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'delta' => $delta,
                'finish_reason' => $finishReason,
            ]],
        ];
    }

    private static function initialToolArguments(mixed $input): string
    {
        if ($input instanceof \stdClass || $input === []) {
            return '';
        }
        if (! is_array($input) || array_is_list($input)) {
            throw self::invalidStream();
        }

        try {
            return json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw self::invalidStream();
        }
    }

    private static function validateToolArguments(string $arguments): void
    {
        if (! str_starts_with(ltrim($arguments), '{')) {
            throw self::invalidStream();
        }
        try {
            $decoded = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::invalidStream();
        }
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw self::invalidStream();
        }
    }

    private static function assertStarted(?string $id, ?string $model): void
    {
        if ($id === null || $model === null) {
            throw self::invalidStream();
        }
    }

    /** @param array<string, mixed> $values */
    private static function requiredString(array $values, string $key): string
    {
        if (! is_string($values[$key] ?? null) || trim($values[$key]) === '') {
            throw self::invalidStream();
        }

        return $values[$key];
    }

    private static function integerIndex(mixed $index): int
    {
        if (! is_int($index) || $index < 0) {
            throw self::invalidStream();
        }

        return $index;
    }

    private static function streamFailure(): AiProxyException
    {
        return new AiProxyException('The AI provider stream failed.', 502);
    }

    private static function invalidStream(): AiProxyException
    {
        return new AiProxyException('The AI provider returned an invalid stream.', 502);
    }

    private static function incompleteStream(): AiProxyException
    {
        return new AiProxyException('The AI provider stream ended unexpectedly.', 502);
    }
}
