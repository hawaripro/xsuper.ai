<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use JsonException;
use stdClass;

final class AnthropicProtocol
{
    private const SUPPORTED_OPTIONS = [
        'model', 'messages', 'stream', 'tools', 'tool_choice', 'max_tokens', 'max_completion_tokens',
        'stop', 'temperature', 'top_p', 'metadata',
    ];

    /** @return array<string, mixed> */
    public static function request(array $payload): array
    {
        self::assertOptions($payload);
        $model = self::requiredString($payload, 'model');
        if (! is_array($payload['messages'] ?? null) || $payload['messages'] === []) {
            throw self::unsupported('messages');
        }

        $system = [];
        $messages = [];
        foreach ($payload['messages'] as $message) {
            if (! is_array($message)) {
                throw self::unsupported('message');
            }
            $role = $message['role'] ?? null;
            if (in_array($role, ['system', 'developer'], true)) {
                foreach (self::content($message['content'] ?? null, true) as $part) {
                    if (($part['type'] ?? null) !== 'text') {
                        throw self::unsupported('system content');
                    }
                    $system[] = $part;
                }

                continue;
            }
            if ($role === 'tool') {
                self::appendToolResult($messages, $message);

                continue;
            }
            if (! in_array($role, ['user', 'assistant'], true)) {
                throw self::unsupported('message role');
            }

            $parts = self::content($message['content'] ?? null, false, $role);
            if ($role === 'assistant' && array_key_exists('tool_calls', $message)) {
                if (! is_array($message['tool_calls']) || $message['tool_calls'] === []) {
                    throw self::unsupported('tool call');
                }
                foreach ($message['tool_calls'] as $call) {
                    $parts[] = self::toolUse($call);
                }
            }
            if ($parts === []) {
                throw self::unsupported('empty message');
            }
            self::appendMessage($messages, $role, $parts);
        }

        $request = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => self::maximumTokens($payload),
        ];
        if ($system !== []) {
            $request['system'] = $system;
        }
        if (array_key_exists('tools', $payload)) {
            $request['tools'] = self::tools($payload['tools']);
        }
        if (array_key_exists('tool_choice', $payload)) {
            $request['tool_choice'] = self::toolChoice($payload['tool_choice']);
        }
        if (array_key_exists('stop', $payload)) {
            $request['stop_sequences'] = self::stopSequences($payload['stop']);
        }
        foreach (['temperature', 'top_p', 'metadata'] as $option) {
            if (array_key_exists($option, $payload) && $payload[$option] !== null && $payload[$option] !== '') {
                $request[$option] = $payload[$option];
            }
        }
        if (($payload['stream'] ?? false) === true) {
            $request['stream'] = true;
        } elseif (array_key_exists('stream', $payload) && ! in_array($payload['stream'], [false, null], true)) {
            throw self::unsupported('stream option');
        }

        return $request;
    }

    /** @return array<string, mixed> */
    public static function response(array $response): array
    {
        $id = self::requiredString($response, 'id', false);
        $model = self::requiredString($response, 'model', false);
        if (! is_array($response['content'] ?? null)) {
            throw self::invalidResponse();
        }

        $text = '';
        $toolCalls = [];
        foreach ($response['content'] as $block) {
            if (! is_array($block)) {
                throw self::invalidResponse();
            }
            if (($block['type'] ?? null) === 'text') {
                if (! is_string($block['text'] ?? null)) {
                    throw self::invalidResponse();
                }
                $text .= $block['text'];

                continue;
            }
            if (($block['type'] ?? null) === 'tool_use') {
                $input = self::objectInput($block['input'] ?? null, false);
                try {
                    $arguments = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } catch (JsonException) {
                    throw self::invalidResponse();
                }
                $toolCalls[] = [
                    'id' => self::requiredString($block, 'id', false),
                    'type' => 'function',
                    'function' => [
                        'name' => self::requiredString($block, 'name', false),
                        'arguments' => $arguments,
                    ],
                ];

                continue;
            }
            if (in_array($block['type'] ?? null, ['thinking', 'redacted_thinking'], true)) {
                continue;
            }

            throw self::invalidResponse();
        }

        $stopReason = $response['stop_reason'] ?? null;
        if (! is_string($stopReason) || $stopReason === '') {
            throw self::invalidResponse();
        }
        if (! is_array($response['usage'] ?? null)) {
            throw self::invalidResponse();
        }

        $message = ['role' => 'assistant', 'content' => $text !== '' ? $text : null];
        if ($toolCalls !== []) {
            $message['tool_calls'] = $toolCalls;
        }

        return [
            'id' => $id,
            'object' => 'chat.completion',
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => $message,
                'finish_reason' => self::finishReason($stopReason),
            ]],
            'usage' => self::usage($response['usage']),
        ];
    }

    /** @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int, prompt_tokens_details?: array<string, int>} */
    public static function usage(array $usage): array
    {
        $input = self::tokens($usage['input_tokens'] ?? 0);
        $cacheRead = self::tokens($usage['cache_read_input_tokens'] ?? 0);
        $cacheCreation = self::tokens($usage['cache_creation_input_tokens'] ?? 0);
        $output = self::tokens($usage['output_tokens'] ?? 0);
        $prompt = $input + $cacheRead + $cacheCreation;
        $mapped = [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $output,
            'total_tokens' => $prompt + $output,
        ];
        if ($cacheRead > 0 || $cacheCreation > 0) {
            $mapped['prompt_tokens_details'] = [
                'cached_tokens' => $cacheRead,
                'cache_creation_tokens' => $cacheCreation,
            ];
        }

        return $mapped;
    }

    public static function finishReason(string $reason): string
    {
        return match ($reason) {
            'end_turn', 'stop_sequence', 'stop' => 'stop',
            'max_tokens', 'model_context_window_exceeded' => 'length',
            'tool_use' => 'tool_calls',
            'refusal' => 'content_filter',
            default => throw self::invalidResponse(),
        };
    }

    /** @return array<int, array<string, mixed>> */
    private static function content(mixed $content, bool $system, string $role = 'user'): array
    {
        if (is_string($content)) {
            return $content === '' ? [] : [['type' => 'text', 'text' => $content]];
        }
        if ($content === null && $role === 'assistant') {
            return [];
        }
        if (! is_array($content)) {
            throw self::unsupported('message content');
        }

        $parts = [];
        foreach ($content as $part) {
            if (! is_array($part)) {
                throw self::unsupported('content part');
            }
            if (($part['type'] ?? null) === 'text') {
                if (! is_string($part['text'] ?? null)) {
                    throw self::unsupported('text content');
                }
                if ($part['text'] !== '') {
                    $parts[] = ['type' => 'text', 'text' => $part['text']];
                }

                continue;
            }
            if ($system) {
                throw self::unsupported('system content');
            }
            if (($part['type'] ?? null) === 'image_url') {
                $url = is_array($part['image_url'] ?? null) ? ($part['image_url']['url'] ?? null) : $part['image_url'] ?? null;
                $parts[] = self::imagePart($url);

                continue;
            }
            if (in_array($part['type'] ?? null, ['file', 'input_file'], true)) {
                $file = is_array($part['file'] ?? null) ? $part['file'] : $part;
                $data = $file['file_data'] ?? $file['data'] ?? null;
                $document = self::dataPart($data, 'document');
                $filename = $file['filename'] ?? null;
                if (is_string($filename) && trim($filename) !== '') {
                    $document['title'] = mb_substr(trim($filename), 0, 255);
                }
                $parts[] = $document;

                continue;
            }

            throw self::unsupported('content type');
        }

        return $parts;
    }

    /** @return array<string, mixed> */
    private static function imagePart(mixed $url): array
    {
        if (is_string($url) && preg_match('/\Ahttps:\/\/[^\s]+\z/i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return ['type' => 'image', 'source' => ['type' => 'url', 'url' => $url]];
        }

        return self::dataPart($url, 'image');
    }

    /** @return array<string, mixed> */
    private static function dataPart(mixed $url, string $kind): array
    {
        if (! is_string($url) || preg_match('/\Adata:([a-z0-9.+-]+\/[a-z0-9.+-]+);base64,([A-Za-z0-9+\/=\r\n]+)\z/i', $url, $matches) !== 1) {
            throw self::unsupported($kind.' URL');
        }
        $mediaType = strtolower($matches[1]);
        $data = str_replace(["\r", "\n"], '', $matches[2]);
        if ($data === '' || base64_decode($data, true) === false) {
            throw self::unsupported($kind.' data');
        }
        if ($kind === 'image' && ! in_array($mediaType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw self::unsupported('image type');
        }
        if ($kind === 'document' && $mediaType !== 'application/pdf') {
            throw self::unsupported('document type');
        }

        return [
            'type' => $kind,
            'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $data],
        ];
    }

    /** @param array<int, array<string, mixed>> $messages */
    private static function appendToolResult(array &$messages, array $message): void
    {
        $toolCallId = self::requiredString($message, 'tool_call_id');
        $content = $message['content'] ?? null;
        if (is_string($content)) {
            $resultContent = $content;
        } elseif (is_array($content)) {
            $resultContent = self::content($content, false);
        } else {
            throw self::unsupported('tool result content');
        }
        $result = [
            'type' => 'tool_result',
            'tool_use_id' => $toolCallId,
            'content' => $resultContent,
        ];
        if (($message['is_error'] ?? false) === true) {
            $result['is_error'] = true;
        }
        self::appendMessage($messages, 'user', [$result]);
    }

    /** @param array<int, array<string, mixed>> $messages */
    private static function appendMessage(array &$messages, string $role, array $parts): void
    {
        $last = array_key_last($messages);
        if ($last !== null && $messages[$last]['role'] === $role) {
            $messages[$last]['content'] = [...$messages[$last]['content'], ...$parts];

            return;
        }
        $messages[] = ['role' => $role, 'content' => $parts];
    }

    /** @return array<string, mixed> */
    private static function toolUse(mixed $call): array
    {
        if (! is_array($call) || ($call['type'] ?? 'function') !== 'function' || ! is_array($call['function'] ?? null)) {
            throw self::unsupported('tool call');
        }
        $function = $call['function'];
        if (! is_string($function['arguments'] ?? null)) {
            throw self::unsupported('tool arguments');
        }
        try {
            $input = json_decode($function['arguments'], false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::unsupported('tool arguments');
        }

        return [
            'type' => 'tool_use',
            'id' => self::requiredString($call, 'id'),
            'name' => self::requiredString($function, 'name'),
            'input' => self::objectInput($input, true),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function tools(mixed $tools): array
    {
        if (! is_array($tools) || $tools === []) {
            throw self::unsupported('tools');
        }
        $mapped = [];
        foreach ($tools as $tool) {
            if (! is_array($tool) || ($tool['type'] ?? null) !== 'function' || ! is_array($tool['function'] ?? null)) {
                throw self::unsupported('tool schema');
            }
            $function = $tool['function'];
            $schema = $function['parameters'] ?? null;
            if (! is_array($schema) || array_is_list($schema)) {
                throw self::unsupported('tool schema');
            }
            $mappedTool = [
                'name' => self::requiredString($function, 'name'),
                'input_schema' => $schema,
            ];
            if (isset($function['description'])) {
                if (! is_string($function['description'])) {
                    throw self::unsupported('tool description');
                }
                $mappedTool['description'] = $function['description'];
            }
            $mapped[] = $mappedTool;
        }

        return $mapped;
    }

    /** @return array<string, mixed> */
    private static function toolChoice(mixed $choice): array
    {
        if (is_string($choice)) {
            return match ($choice) {
                'auto' => ['type' => 'auto'],
                'required', 'any' => ['type' => 'any'],
                'none' => throw self::unsupported('tool choice none'),
                default => throw self::unsupported('tool choice'),
            };
        }
        if (! is_array($choice)) {
            throw self::unsupported('tool choice');
        }
        if (($choice['type'] ?? null) === 'function' && is_array($choice['function'] ?? null)) {
            return ['type' => 'tool', 'name' => self::requiredString($choice['function'], 'name')];
        }
        if (($choice['type'] ?? null) === 'auto') {
            return ['type' => 'auto'];
        }
        if (in_array($choice['type'] ?? null, ['required', 'any'], true)) {
            return ['type' => 'any'];
        }

        throw self::unsupported('tool choice');
    }

    /** @return array<int, string> */
    private static function stopSequences(mixed $stop): array
    {
        $values = is_string($stop) ? [$stop] : $stop;
        if (! is_array($values) || $values === []) {
            throw self::unsupported('stop sequences');
        }
        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                throw self::unsupported('stop sequences');
            }
        }

        return array_values($values);
    }

    private static function maximumTokens(array $payload): int
    {
        if (array_key_exists('max_tokens', $payload) && array_key_exists('max_completion_tokens', $payload)) {
            throw self::unsupported('multiple token limits');
        }
        $value = $payload['max_tokens'] ?? $payload['max_completion_tokens'] ?? 4096;
        if (! is_int($value) || $value < 1) {
            throw self::unsupported('token limit');
        }

        return $value;
    }

    private static function assertOptions(array $payload): void
    {
        foreach (array_keys($payload) as $option) {
            if (! in_array($option, self::SUPPORTED_OPTIONS, true)) {
                throw self::unsupported((string) $option);
            }
        }
    }

    private static function objectInput(mixed $input, bool $request): stdClass|array
    {
        if ($input instanceof stdClass) {
            return $input;
        }
        if ($input === []) {
            return new stdClass;
        }
        if (! is_array($input) || array_is_list($input)) {
            throw $request ? self::unsupported('tool input') : self::invalidResponse();
        }

        return $input;
    }

    private static function tokens(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw self::invalidResponse();
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function requiredString(array $values, string $key, bool $request = true): string
    {
        if (! is_string($values[$key] ?? null) || trim($values[$key]) === '') {
            throw $request ? self::unsupported($key) : self::invalidResponse();
        }

        return $values[$key];
    }

    private static function unsupported(string $item): AiProxyException
    {
        return new AiProxyException('The requested '.$item.' is not supported by this AI provider.', 422);
    }

    private static function invalidResponse(): AiProxyException
    {
        return new AiProxyException('The AI provider returned an invalid response.', 502);
    }
}
