<?php

namespace App\Services\Api;

use App\Exceptions\AiProxyException;
use App\Services\UsageBillingService;
use Generator;
use Illuminate\Support\Str;
use JsonException;
use stdClass;

/** The Messages wire protocol over an OpenAI-compatible chat provider. Native Anthropic bypasses this bridge. */
final class AnthropicBridge
{
    public function __construct(private readonly UsageBillingService $billing) {}

    public function request(array $body): array
    {
        $messages = [];
        if (isset($body['system'])) {
            $messages[] = ['role' => 'system', 'content' => $this->content($body['system'], true)];
        }
        foreach ($body['messages'] as $message) {
            if (is_string($message['content'])) {
                $messages[] = $message;
                continue;
            }
            if (! is_array($message['content'])) {
                throw $this->invalid('Message content must be a string or content blocks.');
            }
            $parts = [];
            $calls = [];
            $results = [];
            foreach ($message['content'] as $block) {
                if (! is_array($block)) {
                    throw $this->invalid('Invalid content block.');
                }
                switch ($block['type'] ?? null) {
                    case 'thinking':
                    case 'redacted_thinking':
                        break;
                    case 'tool_use':
                        if ($message['role'] !== 'assistant') { throw $this->invalid('Tool use requires the assistant role.'); }
                        $input = $block['input'] ?? null;
                        if (! $input instanceof stdClass && (! is_array($input) || ($input !== [] && array_is_list($input)))) { throw $this->invalid('Tool input must be an object.'); }
                        $calls[] = ['id' => $this->string($block, 'id'), 'type' => 'function', 'function' => [
                            'name' => $this->string($block, 'name'), 'arguments' => json_encode((object) $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        ]];
                        break;
                    case 'tool_result':
                        if ($message['role'] !== 'user') { throw $this->invalid('Tool results require the user role.'); }
                        $results[] = ['role' => 'tool', 'tool_call_id' => $this->string($block, 'tool_use_id'), 'content' => $this->content($block['content'] ?? '')];
                        break;
                    default:
                        $parts = [...$parts, ...$this->content([$block])];
                }
            }
            // OpenAI requires tool replies immediately after assistant tool_calls, before any user follow-up text.
            array_push($messages, ...$results);
            if ($parts !== [] || $calls !== []) {
                $out = ['role' => $message['role'], 'content' => $parts ?: null];
                if ($calls !== []) { $out['tool_calls'] = $calls; }
                $messages[] = $out;
            }
        }
        $options = array_intersect_key($body, array_flip(['max_tokens', 'top_p', 'temperature']));
        if (isset($body['stop_sequences'])) { $options['stop'] = $body['stop_sequences']; }
        $metadata = (array) ($body['metadata'] ?? []);
        if (isset($metadata['user_id'])) { $options['user'] = $metadata['user_id']; }
        if (isset($body['tools'])) {
            $options['tools'] = array_map(function (array $tool): array {
                $function = ['name' => $this->string($tool, 'name')];
                if (isset($tool['description'])) { $function['description'] = $tool['description']; }
                $function['parameters'] = $tool['input_schema'];
                return ['type' => 'function', 'function' => $function];
            }, $body['tools']);
        }
        if (isset($body['tool_choice'])) {
            $choice = $body['tool_choice'];
            $options['tool_choice'] = match ($choice['type'] ?? null) {
                'auto' => 'auto', 'any' => 'required', 'none' => 'none',
                'tool' => ['type' => 'function', 'function' => ['name' => $this->string($choice, 'name')]],
                default => throw $this->invalid('Invalid tool choice.'),
            };
            if (isset($choice['disable_parallel_tool_use'])) { $options['parallel_tool_calls'] = ! $choice['disable_parallel_tool_use']; }
        }
        if ($body['stream'] ?? false) { $options['stream_options'] = ['include_usage' => true]; }

        return ['messages' => $messages, 'options' => $options];
    }

    public function response(array $data, string $model): array
    {
        $choice = $data['choices'][0] ?? [];
        $content = [];
        $text = $choice['message']['content'] ?? '';
        if (is_array($text)) { $text = implode('', array_column($text, 'text')); }
        if (is_string($text) && $text !== '') { $content[] = ['type' => 'text', 'text' => $text]; }
        foreach ($choice['message']['tool_calls'] ?? [] as $call) {
            $content[] = ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['function']['name'], 'input' => $this->toolInput($call['function']['arguments'] ?? '')];
        }

        return [
            'id' => 'msg_'.Str::random(24), 'type' => 'message', 'role' => 'assistant', 'model' => $model,
            'content' => $content, 'stop_reason' => $this->stopReason($choice['finish_reason'] ?? null), 'stop_sequence' => null,
            'usage' => $this->usage($data['usage'] ?? []),
        ];
    }

    /** @return Generator<int, array<string, mixed>> */
    public function stream(iterable $events, string $model, int $estimatedInput): Generator
    {
        yield ['type' => 'message_start', 'message' => [
            'id' => 'msg_'.Str::random(24), 'type' => 'message', 'role' => 'assistant', 'model' => $model,
            'content' => [], 'stop_reason' => null, 'stop_sequence' => null,
            'usage' => ['input_tokens' => $estimatedInput, 'output_tokens' => 0],
        ]];
        $nextIndex = 0;
        $textIndex = null;
        $tools = [];
        $usage = [];
        $finish = null;
        foreach ($events as $event) {
            if (is_array($event['usage'] ?? null)) { $usage = $event['usage']; }
            $choice = $event['choices'][0] ?? [];
            if ($choice['finish_reason'] ?? null) { $finish = $choice['finish_reason']; }
            $delta = (array) ($choice['delta'] ?? []);
            if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
                if ($textIndex === null) {
                    $textIndex = $nextIndex++;
                    yield ['type' => 'content_block_start', 'index' => $textIndex, 'content_block' => ['type' => 'text', 'text' => '']];
                }
                yield ['type' => 'content_block_delta', 'index' => $textIndex, 'delta' => ['type' => 'text_delta', 'text' => $delta['content']]];
            }
            foreach ($delta['tool_calls'] ?? [] as $call) {
                if ($textIndex !== null) {
                    yield ['type' => 'content_block_stop', 'index' => $textIndex];
                    $textIndex = null;
                }
                $index = $call['index'] ?? 0;
                $tool = $tools[$index] ?? ['index' => $nextIndex++, 'id' => '', 'name' => '', 'arguments' => '', 'pending' => [], 'started' => false];
                $tool['id'] .= $call['id'] ?? '';
                $tool['name'] .= $call['function']['name'] ?? '';
                $fragment = $call['function']['arguments'] ?? '';
                if ($fragment !== '') { $tool['arguments'] .= $fragment; $tool['pending'][] = $fragment; }
                if (! $tool['started'] && $tool['id'] !== '' && $tool['name'] !== '') {
                    yield ['type' => 'content_block_start', 'index' => $tool['index'], 'content_block' => ['type' => 'tool_use', 'id' => $tool['id'], 'name' => $tool['name'], 'input' => new stdClass]];
                    $tool['started'] = true;
                }
                if ($tool['started']) {
                    foreach ($tool['pending'] as $partial) {
                        yield ['type' => 'content_block_delta', 'index' => $tool['index'], 'delta' => ['type' => 'input_json_delta', 'partial_json' => $partial]];
                    }
                    $tool['pending'] = [];
                }
                $tools[$index] = $tool;
            }
        }
        if ($textIndex !== null) { yield ['type' => 'content_block_stop', 'index' => $textIndex]; }
        foreach ($tools as $tool) {
            if (! $tool['started']) { throw new AiProxyException('The AI provider returned an incomplete tool call.', 502); }
            $this->toolInput($tool['arguments'] === '' ? '{}' : $tool['arguments']);
            yield ['type' => 'content_block_stop', 'index' => $tool['index']];
        }
        yield ['type' => 'message_delta', 'delta' => ['stop_reason' => $this->stopReason($finish), 'stop_sequence' => null], 'usage' => $this->usage($usage)];
        yield ['type' => 'message_stop'];
    }

    public function usage(array $raw): array
    {
        $usage = $this->billing->normalizeUsage($raw);
        if (! isset($usage['prompt_tokens'], $usage['completion_tokens'])) { return []; }
        $read = $usage['cache_read_tokens'] ?? 0;
        $write = $usage['cache_write_tokens'] ?? 0;

        return ['input_tokens' => max(0, $usage['prompt_tokens'] - $read - $write), 'cache_read_input_tokens' => $read,
            'cache_creation_input_tokens' => $write, 'output_tokens' => $usage['completion_tokens']];
    }

    private function content(mixed $content, bool $system = false): array|string
    {
        if (is_string($content)) { return $content; }
        if (! is_array($content)) { throw $this->invalid('Invalid message content.'); }
        return array_map(function ($block) use ($system): array {
            if (! is_array($block)) { throw $this->invalid('Invalid content block.'); }
            if (($block['type'] ?? null) === 'text') { return ['type' => 'text', 'text' => $this->string($block, 'text', true)]; }
            if (! $system && ($block['type'] ?? null) === 'image') {
                $source = $block['source'] ?? [];
                $url = match ($source['type'] ?? null) {
                    'url' => $this->string($source, 'url'),
                    'base64' => 'data:'.$this->string($source, 'media_type').';base64,'.$this->string($source, 'data'),
                    default => throw $this->invalid('Invalid image source.'),
                };
                return ['type' => 'image_url', 'image_url' => ['url' => $url]];
            }
            throw $this->invalid('Unsupported content block.');
        }, $content);
    }

    private function toolInput(string $json): stdClass
    {
        try { $input = json_decode($json, false, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new AiProxyException('The AI provider returned invalid tool input.', 502); }
        if (! $input instanceof stdClass) { throw new AiProxyException('The AI provider returned invalid tool input.', 502); }
        return $input;
    }

    private function stopReason(?string $finish): string
    {
        return match ($finish) { 'length' => 'max_tokens', 'tool_calls', 'function_call' => 'tool_use', default => 'end_turn' };
    }

    private function string(array $values, string $field, bool $empty = false): string
    {
        if (! is_string($values[$field] ?? null) || (! $empty && $values[$field] === '')) { throw $this->invalid('Invalid '.$field.'.'); }
        return $values[$field];
    }

    private function invalid(string $message): AiProxyException
    {
        return new AiProxyException($message, 400);
    }
}
