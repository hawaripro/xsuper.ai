<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiProxyException;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Api\ApiErrorResponse;
use App\Http\Controllers\Controller;
use App\Models\UsageLog;
use App\Models\Wallet;
use App\Services\AiProxyService;
use App\Services\Api\AnthropicBridge;
use App\Services\Api\ApiModelCatalog;
use App\Services\UsageBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ExternalApiController extends Controller
{
    private const BLOCKED_RESPONSE_FRAGMENTS = ['eno'.'wx'];

    public function __construct(
        private readonly AiProxyService $aiProxy,
        private readonly UsageBillingService $billing,
        private readonly ApiModelCatalog $catalog,
        private readonly AnthropicBridge $bridge,
    ) {}

    public function models(Request $request): JsonResponse
    {
        $models = $this->catalog->models($request->attributes->get('api_key'));
        if ($request->hasHeader('anthropic-version')) {
            $models = array_map(static fn (array $model): array => [
                'type' => 'model', 'id' => $model['id'], 'display_name' => $model['name'], 'created_at' => gmdate('Y-m-d\TH:i:s\Z', $model['created']),
            ], $models);
            return response()->json(['data' => $models, 'has_more' => false, 'first_id' => $models[0]['id'] ?? null, 'last_id' => $models === [] ? null : $models[array_key_last($models)]['id']]);
        }

        return response()->json(['object' => 'list', 'data' => $models]);
    }

    public function chatCompletions(Request $request)
    {
        $body = $request->validate([
            'model' => 'required|string|max:160', 'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|string|in:system,developer,user,assistant,tool', 'messages.*.content' => 'nullable',
            'messages.*.name' => 'sometimes|string|max:120', 'messages.*.tool_calls' => 'sometimes|array', 'messages.*.tool_call_id' => 'sometimes|string|max:256',
            'stream' => 'sometimes|boolean', 'tools' => 'sometimes|array', 'tool_choice' => 'sometimes', 'parallel_tool_calls' => 'sometimes|boolean',
            'temperature' => 'nullable|numeric', 'top_p' => 'nullable|numeric', 'max_tokens' => 'nullable|integer|min:1|max:1000000',
            'max_completion_tokens' => 'nullable|integer|min:1|max:1000000', 'frequency_penalty' => 'nullable|numeric', 'presence_penalty' => 'nullable|numeric',
            'stop' => 'nullable', 'response_format' => 'sometimes|array', 'reasoning_effort' => 'sometimes|string|max:32',
            'stream_options' => 'sometimes|array', 'seed' => 'sometimes|integer', 'user' => 'sometimes|string|max:256',
            'metadata' => 'sometimes|array', 'n' => 'sometimes|integer|in:1', 'logit_bias' => 'sometimes|array', 'logprobs' => 'sometimes|boolean',
            'top_logprobs' => 'sometimes|integer|min:0|max:20', 'service_tier' => 'sometimes|string', 'modalities' => 'sometimes|array',
            'audio' => 'sometimes|array', 'prediction' => 'sometimes|array',
        ]);
        if ($error = $this->modelError($request, $body['model'], false)) { return $error; }
        $options = array_diff_key($body, array_flip(['model', 'messages', 'stream']));
        $maximum = max((int) ($body['max_tokens'] ?? 0), (int) ($body['max_completion_tokens'] ?? 0)) ?: 4096;
        $user = $request->attributes->get('api_user');
        try {
            $reservation = $this->billing->reserveApi($user->id, $body['model'], $this->billing->estimateInputTokens($body['messages'], $options), $maximum, 'api:'.Str::uuid());
        } catch (InsufficientBalanceException $exception) {
            return ApiErrorResponse::insufficientBalance($exception, ApiErrorResponse::OPENAI);
        }
        if ($body['stream'] ?? false) {
            $events = $this->aiProxy->streamChatCompletion($body['messages'], $body['model'], $options, $maximum);
            return $this->stream($request, $body['model'], $events, $reservation, false);
        }
        try {
            $data = $this->aiProxy->chatCompletion($body['messages'], $body['model'], $options, $maximum);
        } catch (Throwable $exception) {
            Wallet::release($user->id, $reservation, 'API upstream request failed');
            return $this->providerError($exception, false);
        }
        try {
            $usage = $this->billing->normalizeUsage((array) ($data['usage'] ?? []));
            $cost = $this->settle($request, $body['model'], $usage, $reservation);
        } catch (Throwable) {
            return ApiErrorResponse::openAi('The response completed, but usage billing could not be finalized.', 'billing_error', 'billing_error', 502);
        }
        $data['usage'] = [...$usage, 'cost_usd' => $cost / 1_000_000, 'balance_usd' => Wallet::balance($user->id) / 1_000_000];
        return response()->json($data);
    }

    public function messages(Request $request)
    {
        $this->validateMessages($request);
        // Native Anthropic fields (including cache_control and thinking signatures) are not round-tripped through OpenAI.
        $body = [...$request->all(), 'max_tokens' => $request->input('max_tokens', 4096)];
        $model = $body['model'];
        if ($error = $this->modelError($request, $model, true)) { return $error; }
        $user = $request->attributes->get('api_user');
        try {
            $protocol = $this->aiProxy->protocolForModel($model);
            if ($protocol === 'fal' && ! empty($body['tools'])) {
                return ApiErrorResponse::anthropic('invalid_request_error', 'Tools are not supported by this model.', 400);
            }
            $native = $protocol === 'anthropic';
            $translated = $native ? null : $this->bridge->request($body);
            $estimate = $this->billing->estimateInputTokens($body['messages'], array_intersect_key($body, array_flip(['system', 'tools'])));
            $reservation = $this->billing->reserveApi($user->id, $model, $estimate, $body['max_tokens'], 'api:'.Str::uuid());
        } catch (InsufficientBalanceException $exception) {
            return ApiErrorResponse::insufficientBalance($exception, ApiErrorResponse::ANTHROPIC);
        } catch (Throwable $exception) {
            return $this->providerError($exception, true);
        }
        $headers = [];
        foreach (['anthropic-version', 'anthropic-beta'] as $header) {
            if ($request->hasHeader($header)) { $headers[$header] = $request->header($header); }
        }
        if ($body['stream'] ?? false) {
            $events = $native ? $this->aiProxy->nativeMessageStream($body, $headers)
                : $this->bridge->stream($this->aiProxy->streamChatCompletion($translated['messages'], $model, $translated['options'], $body['max_tokens']), $model, $estimate);
            return $this->stream($request, $model, $events, $reservation, true, $native);
        }
        try {
            $data = $native ? $this->aiProxy->nativeMessages($body, $headers)
                : $this->bridge->response($this->aiProxy->chatCompletion($translated['messages'], $model, $translated['options'], $body['max_tokens']), $model);
        } catch (Throwable $exception) {
            Wallet::release($user->id, $reservation, 'Messages upstream request failed');
            return $this->providerError($exception, true);
        }
        try {
            $this->settle($request, $model, $this->billing->normalizeUsage((array) ($data['usage'] ?? [])), $reservation);
        } catch (Throwable) {
            return ApiErrorResponse::anthropic('billing_error', 'The response completed, but usage billing could not be finalized.', 502);
        }
        return response()->json($data);
    }

    public function countTokens(Request $request): JsonResponse
    {
        $this->validateMessages($request);
        if ($error = $this->modelError($request, $request->input('model'), true)) { return $error; }
        return response()->json(['input_tokens' => $this->billing->estimateInputTokens($request->input('messages'), $request->only(['system', 'tools']))]);
    }

    private function validateMessages(Request $request): void
    {
        $request->validate([
            'model' => 'required|string|max:160', 'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|string|in:user,assistant', 'messages.*.content' => 'required',
            'system' => 'sometimes', 'max_tokens' => 'sometimes|integer|min:1|max:1000000', 'stream' => 'sometimes|boolean',
            'temperature' => 'sometimes|numeric', 'top_p' => 'sometimes|numeric', 'top_k' => 'sometimes|integer|min:0',
            'stop_sequences' => 'sometimes|array', 'stop_sequences.*' => 'string', 'metadata' => 'sometimes|array',
            'metadata.user_id' => 'sometimes|string|max:256', 'tools' => 'sometimes|array', 'tools.*.name' => 'required|string|max:128',
            'tools.*.description' => 'sometimes|string', 'tools.*.input_schema' => 'required|array',
            'tool_choice' => 'sometimes|array', 'tool_choice.type' => 'required_with:tool_choice|in:auto,any,tool,none',
            'tool_choice.name' => 'required_if:tool_choice.type,tool|string', 'tool_choice.disable_parallel_tool_use' => 'sometimes|boolean',
        ]);
    }

    private function modelError(Request $request, string $model, bool $anthropic): ?JsonResponse
    {
        $key = $request->attributes->get('api_key');
        if ($key->allowed_models && ! in_array($model, $key->allowed_models, true)) {
            return $anthropic ? ApiErrorResponse::anthropic('permission_error', 'Model not allowed', 403)
                : ApiErrorResponse::openAi('Model not allowed', 'permission_error', 'model_not_allowed', 403);
        }
        if (! in_array($model, array_column($this->catalog->models(), 'id'), true)) {
            return $anthropic ? ApiErrorResponse::anthropic('invalid_request_error', 'Model not found or unavailable', 400)
                : ApiErrorResponse::openAi('Model not found or unavailable', 'invalid_request_error', 'model_not_found', 400);
        }
        return null;
    }

    private function settle(Request $request, string $model, array $usage, array $reservation): int
    {
        $user = $request->attributes->get('api_user');
        $cost = $this->billing->settleApi($user->id, $model, $usage, $reservation);
        UsageLog::record($user->id, $model, [...$usage, 'cost_microusd' => $cost, 'api_key_id' => $request->attributes->get('api_key')->id], 'api');
        return $cost;
    }

    private function stream(Request $request, string $model, iterable $events, array $reservation, bool $anthropic, bool $native = false): StreamedResponse
    {
        return new StreamedResponse(function () use ($request, $model, $events, $reservation, $anthropic, $native): void {
            $user = $request->attributes->get('api_user');
            $usage = [];
            $output = false;
            $completed = false;
            $stop = null;
            try {
                foreach ($events as $frame) {
                    $event = $native ? $frame['data'] : $frame;
                    if ($anthropic) {
                        if (($event['type'] ?? null) === 'message_start') {
                            // Bridged message_start is an estimate, not provider billing evidence.
                            if ($native) { $usage = (array) ($event['message']['usage'] ?? []); }
                        } elseif (($event['type'] ?? null) === 'message_delta') {
                            $usage = array_replace($usage, (array) ($event['usage'] ?? []));
                        }
                        $output = $output || in_array($event['type'] ?? null, ['content_block_delta'], true)
                            || (($event['type'] ?? null) === 'content_block_start' && (($event['content_block']['type'] ?? null) === 'tool_use' || ! empty($event['content_block']['text'])));
                        if (($event['type'] ?? null) === 'message_stop') { $stop = $frame; continue; }
                    } else {
                        if (is_array($event['usage'] ?? null)) { $usage = $this->billing->normalizeUsage($event['usage']); $event['usage'] = $usage; }
                        foreach ($event['choices'] ?? [] as $choice) {
                            $delta = (array) ($choice['delta'] ?? []);
                            $output = $output || ! empty($delta['content']) || ! empty($delta['tool_calls']) || ! empty($delta['refusal']);
                        }
                    }
                    $this->emit($event, $anthropic, $native ? $frame['raw'] : null);
                }
                $completed = true;
                $usage = $this->billing->normalizeUsage($usage);
                $cost = $this->settle($request, $model, $usage, $reservation);
                if ($anthropic) {
                    if ($stop !== null) { $this->emit($native ? $stop['data'] : $stop, true, $native ? $stop['raw'] : null); }
                } else {
                    $this->emit(['object' => 'chat.completion.chunk', 'model' => $model, 'choices' => [], 'usage' => [
                        ...$usage, 'cost_usd' => $cost / 1_000_000, 'balance_usd' => Wallet::balance($user->id) / 1_000_000,
                    ]], false);
                    echo "data: [DONE]\n\n";
                }
            } catch (Throwable $exception) {
                if (! $output && ! $completed) { Wallet::release($user->id, $reservation, 'API stream failed before output'); }
                $message = $completed ? 'The response completed, but usage billing could not be finalized.' : 'The AI provider stream failed. Please try again later.';
                $error = ['type' => $completed ? 'billing_error' : ($anthropic ? 'api_error' : 'upstream_error'), 'message' => $message];
                $this->emit($anthropic ? ['type' => 'error', 'error' => $error] : ['error' => [...$error, 'code' => $error['type']]], $anthropic);
            }
            if (ob_get_level() > 0) { ob_flush(); }
            flush();
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Connection' => 'keep-alive', 'X-Accel-Buffering' => 'no']);
    }

    private function emit(array $event, bool $anthropic, ?string $raw = null): void
    {
        echo $raw ?? (($anthropic ? 'event: '.$event['type']."\n" : '').'data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n");
        if (ob_get_level() > 0) { ob_flush(); }
        flush();
    }

    private function providerError(Throwable $exception, bool $anthropic): JsonResponse
    {
        $status = $exception instanceof AiProxyException ? $exception->responseStatus() : 502;
        $invalid = in_array($status, [400, 422], true);
        if ($invalid) { $status = 400; }
        $message = $exception instanceof AiProxyException ? $exception->getMessage() : 'The AI provider is unavailable. Please try again later.';
        return $anthropic ? ApiErrorResponse::anthropic($invalid ? 'invalid_request_error' : 'api_error', $message, $status)
            : ApiErrorResponse::openAi($message, $invalid ? 'invalid_request_error' : 'upstream_error', $invalid ? 'invalid_request_error' : 'upstream_error', $status);
    }

    public static function clean(string $text): string
    {
        return str_ireplace(self::BLOCKED_RESPONSE_FRAGMENTS, 'XSuper.ai', $text);
    }

    public static function deepClean(string $text): string
    {
        return trim($text);
    }
}
