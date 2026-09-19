<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Http\Controllers\Api\ExternalApiController;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use Generator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AiProxyService
{
    private const BLOCKED_MODEL_IDS = ['au'.'to'];

    private const BLOCKED_MODEL_FRAGMENTS = ['eno'.'wx'];

    private const BLOCKED_MODEL_NAMES = ['au'.'to', 'au'.'to router'];

    public function __construct(private readonly AiProviderTransport $transport) {}

    /**
     * Get available AI models (filtered to chat category)
     */
    public function getModels(array $allowedTiers = ['Standard', 'MAX']): array
    {
        $categories = in_array('Canva', $allowedTiers, true) ? ['chat', 'image', 'canva'] : ['chat'];

        return collect($this->getAllModelsFiltered($allowedTiers))
            ->filter(fn (array $model): bool => in_array($model['category'], $categories, true))
            ->map(fn (array $model): array => [
                'id' => $model['id'],
                'name' => $model['name'],
                'category' => $model['tier'],
            ])
            ->values()
            ->all();
    }

    /**
     * Get all models (unfiltered)
     */
    public function getAllModels(): array
    {
        $profiles = Schema::hasTable('ai_model_profiles') ? AiModelProfile::query()->with('provider')->get() : collect();
        if ($profiles->isNotEmpty()) {
            return $this->sanitizeModels($profiles
                ->filter(fn (AiModelProfile $profile): bool => $profile->is_enabled
                    && $profile->is_available && $profile->provider?->is_enabled)
                ->map(fn (AiModelProfile $profile): array => [
                    'id' => $profile->model_id,
                    'name' => $profile->display_name ?: $profile->model_id,
                    'provider' => $profile->provider_name,
                    'category' => $profile->category,
                    'tier' => $profile->tier,
                    'context_length' => $profile->context_window,
                    'max_output_tokens' => $profile->max_output_tokens,
                    'capabilities' => $profile->capabilities ?? [],
                    'input_modalities' => $profile->input_modalities ?? ['text'],
                    'output_modalities' => $profile->output_modalities ?? ['text'],
                ])->values()->all());
        }

        return [];

    }

    public function fetchCatalog(?AiProviderProfile $provider = null): array
    {
        $models = $this->transport->catalog($provider);
        $sensitive = array_values(array_filter([
            $provider?->base_url !== null ? $provider->api_key : config('services.ai_proxy.key', ''),
            $provider?->base_url ?? config('services.ai_proxy.url', ''),
        ], fn ($value): bool => is_string($value) && $value !== ''));

        return collect($this->sanitizeModels(array_filter($models, 'is_array')))
            ->map(fn (array $model): ?array => $this->catalogModel($model, $sensitive))
            ->filter()
            ->values()
            ->all();
    }

    public function generateImages(string $model, string $prompt, string $size, int $quantity, ?\Closure $beforeRequest = null): array
    {
        [$provider, $upstreamModel] = $this->routeModel($model);
        $profile = AiModelProfile::query()->where('model_id', $model)->firstOrFail();
        $config = MediaModelConfig::forModel($profile);
        $payload = ['model' => $upstreamModel, 'prompt' => $prompt];
        if ($config['supports_size'] && $size !== 'auto') {
            $payload['size'] = $size;
        }
        if ($config['supports_n']) {
            $payload['n'] = $quantity;
        }
        $requests = $config['supports_n'] ? 1 : $quantity;
        $images = [];
        for ($index = 0; $index < $requests; $index++) {
            $beforeRequest?->__invoke();
            $response = $this->transport->imageGeneration($provider, $payload, $config['image_path']);
            $items = $response['data'] ?? null;
            if (! is_array($items) || count($items) !== ($config['supports_n'] ? $quantity : 1)) {
                throw new AiProxyException('The AI image provider returned an incomplete response.', 502);
            }
            foreach ($items as $item) {
                if (! is_array($item) || (! is_string($item['url'] ?? null) && ! is_string($item['b64_json'] ?? null))) {
                    throw new AiProxyException('The AI image provider returned an invalid image.', 502);
                }
                $images[] = array_intersect_key($item, array_flip(['url', 'b64_json']));
            }
        }

        return $images;
    }

    /**
     * Send chat completion (non-streaming)
     */
    public function chatCompletion(array $messages, string $model, array $options = [], ?int $defaultOutputLimit = null): array
    {
        [$provider, $upstreamModel] = $this->routeModel($model);
        $options = $this->withOutputLimit($options, $provider, $model, $defaultOutputLimit);
        $result = $this->transport->complete($provider, [
            ...$options,
            'model' => $upstreamModel,
            'messages' => $messages,
            'stream' => false,
        ]);

        return $this->publicResponse($result, $model);
    }

    public function streamChatCompletion(array $messages, string $model, array $options = [], ?int $defaultOutputLimit = null): Generator
    {
        [$provider, $upstreamModel] = $this->routeModel($model);
        $options = $this->withOutputLimit($options, $provider, $model, $defaultOutputLimit);
        $buffers = [];
        foreach ($this->transport->stream($provider, [
            ...$options,
            'model' => $upstreamModel,
            'messages' => $messages,
            'stream' => true,
        ]) as $event) {
            $event = $this->publicResponse($event, $model);
            foreach ($event['choices'] ?? [] as $position => $choice) {
                $index = $choice['index'] ?? $position;
                $choice['delta'] = (array) ($choice['delta'] ?? []);
                $event['choices'][$position]['delta'] = $choice['delta'];
                foreach (['content', 'refusal'] as $field) {
                    $text = $choice['delta'][$field] ?? null;
                    if (is_string($text)) {
                        $buffers[$index][$field] = ($buffers[$index][$field] ?? '').$text;
                        $safe = $this->drainStreamText($buffers[$index][$field]);
                        if ($safe === '') {
                            unset($event['choices'][$position]['delta'][$field]);
                        } else {
                            $event['choices'][$position]['delta'][$field] = $safe;
                        }
                    }
                }
                if (($choice['finish_reason'] ?? null) !== null) {
                    foreach ($buffers[$index] ?? [] as $field => $remaining) {
                        if ($remaining !== '') {
                            $event['choices'][$position]['delta'][$field] = ($event['choices'][$position]['delta'][$field] ?? '').ExternalApiController::clean($remaining);
                        }
                    }
                    unset($buffers[$index]);
                }
                if (($event['choices'][$position]['delta'] ?? null) === []) {
                    $event['choices'][$position]['delta'] = new \stdClass;
                }
            }
            yield $event;
        }
        foreach ($buffers as $index => $fields) {
            $delta = [];
            foreach ($fields as $field => $remaining) {
                if ($remaining !== '') {
                    $delta[$field] = ExternalApiController::clean($remaining);
                }
            }
            if ($delta !== []) {
                yield [
                    'object' => 'chat.completion.chunk',
                    'model' => $model,
                    'choices' => [['index' => $index, 'delta' => $delta, 'finish_reason' => null]],
                ];
            }
        }
    }

    /**
     * Send chat completion with streaming (SSE)
     * Returns a StreamedResponse for direct use in controllers
     */
    public function chatCompletionStream(array $messages, string $model, ?\Closure $onChunk = null): StreamedResponse
    {
        return new StreamedResponse(function () use ($messages, $model, $onChunk): void {
            $fullResponse = '';
            $usage = null;
            try {
                foreach ($this->streamChatCompletion($messages, $model) as $event) {
                    $delta = (array) ($event['choices'][0]['delta'] ?? []);
                    $content = $delta['content'] ?? $delta['refusal'] ?? null;
                    if (is_string($content)) {
                        $fullResponse .= $content;
                    }
                    if (is_array($event['usage'] ?? null)) {
                        $usage = $event['usage'];
                    }
                    echo 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
                if ($onChunk && $fullResponse !== '') {
                    $onChunk($fullResponse, $usage);
                }
                echo "data: [DONE]\n\n";
            } catch (Throwable $exception) {
                $status = $exception instanceof AiProxyException ? $exception->responseStatus() : 502;
                Log::warning('AI stream failed', ['status' => $status]);
                $message = $exception instanceof AiProxyException
                    ? $exception->getMessage()
                    : 'The AI provider is unavailable. Please try again later.';
                echo 'data: '.json_encode(['error' => ['message' => $message, 'type' => 'upstream_error']], JSON_THROW_ON_ERROR)."\n\n";
            }
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Check if AI proxy is reachable
     */
    public function healthCheck(): bool
    {
        return $this->getStatus()['online'];
    }

    /**
     * Get proxy status info
     */
    public function getStatus(): array
    {
        $providers = AiProviderProfile::query()->where('is_enabled', true)->get();
        $online = $providers->contains(fn (AiProviderProfile $provider): bool => $provider->status === 'healthy');
        $models = $this->getAllModels();

        return [
            'online' => $online,
            'total_models' => count($models),
            'chat_models' => count(array_filter($models, fn (array $model): bool => ($model['category'] ?? 'chat') === 'chat')),
        ];
    }

    /**
     * Get all models across ALL categories (chat, image, video, audio) with tier filtering
     * Used by the full-page chat UI
     */
    public function getAllModelsFiltered(array $allowedTiers = ['Standard', 'MAX']): array
    {
        return $allowedTiers === [] ? [] : $this->filterModelsForTiers($this->getAllModels(), $allowedTiers);
    }

    public function filterModelsForTiers(array $models, array $allowedTiers): array
    {
        if ($allowedTiers === []) {
            return [];
        }

        $models = collect($models)->keyBy('id');

        $rawTiers = ['Original' => 'Standard', 'Authentic' => 'MAX'];
        $tierLabels = [
            'Standard' => 'Original', 'MAX' => 'Authentic',
            'Codex' => 'Codex', 'Wavespeed' => 'Wavespeed', 'YepAPI' => 'YepAPI', 'Canva' => 'Canva',
            'Original' => 'Original', 'Authentic' => 'Authentic',
        ];
        $authenticOnly = ['claude-opus-4.6', 'claude-opus-4.7', 'gpt-5.5'];

        return collect($this->sanitizeModels($models->values()->all()))
            ->filter(function (array $model) use ($allowedTiers, $rawTiers, $authenticOnly): bool {
                $tier = $rawTiers[$model['tier'] ?? ''] ?? ($model['tier'] ?? '');

                return in_array($tier, $allowedTiers, true)
                    && ! str_contains(strtolower($model['id']), 'default')
                    && ! (in_array($model['id'], $authenticOnly, true) && $tier === 'Standard');
            })
            ->map(fn (array $model): array => $this->scrubModelFull($model, $tierLabels))
            ->values()
            ->all();
    }

    public function firstAvailableModel(array $allowedTiers = []): ?string
    {
        return $this->getAllModelsFiltered($allowedTiers)[0]['id'] ?? null;
    }

    private function routeModel(string $model): array
    {
        if ($this->sanitizeModels([['id' => $model]]) === []) {
            throw new AiProxyException('The selected model is unavailable.', 403);
        }
        $profile = AiModelProfile::query()->with('provider')->where('model_id', $model)->first();
        if ($profile) {
            if (! $profile->is_enabled || ! $profile->is_available || ! $profile->provider?->is_enabled) {
                throw new AiProxyException('The selected model is unavailable.', 403);
            }

            return [$profile->provider, $profile->upstream_model_id ?: $profile->model_id];
        }
        throw new AiProxyException('The selected model is unavailable.', 403);
    }

    /**
     * Web chat sends no explicit token limit. Use the catalog's per-model output
     * limit when it is known; otherwise let the provider apply the model maximum
     * instead of a fixed 4096, which truncated long and reasoning-heavy answers.
     * API callers pass the limit their wallet reservation covers.
     */
    private function withOutputLimit(array $options, ?AiProviderProfile $provider, string $model, ?int $default): array
    {
        if (isset($options['max_tokens']) || isset($options['max_completion_tokens'])) {
            return $options;
        }
        $limit = $default ?? (int) (AiModelProfile::query()->where('model_id', $model)->value('max_output_tokens') ?? 0);
        if ($limit > 0) {
            $key = $provider?->base_url !== null && $provider->protocol === 'openai' ? 'max_completion_tokens' : 'max_tokens';
            $options[$key] = $limit;
        }

        return $options;
    }

    private function publicResponse(array $response, string $model): array
    {
        $response = array_intersect_key($response, array_flip(['id', 'object', 'created', 'model', 'choices', 'usage']));
        $response['model'] = $model;
        foreach ($response['choices'] ?? [] as $position => $choice) {
            foreach (['content', 'refusal'] as $field) {
                if (is_string($choice['message'][$field] ?? null)) {
                    $response['choices'][$position]['message'][$field] = ExternalApiController::deepClean(ExternalApiController::clean($choice['message'][$field]));
                }
            }
        }

        return $response;
    }

    private function drainStreamText(string &$buffer): string
    {
        $retain = 0;
        foreach (self::BLOCKED_MODEL_FRAGMENTS as $fragment) {
            for ($length = 1; $length < strlen($fragment); $length++) {
                if (strlen($buffer) >= $length && strcasecmp(substr($buffer, -$length), substr($fragment, 0, $length)) === 0) {
                    $retain = max($retain, $length);
                }
            }
        }
        $text = $retain > 0 ? substr($buffer, 0, -$retain) : $buffer;
        $buffer = $retain > 0 ? substr($buffer, -$retain) : '';

        return ExternalApiController::clean($text);
    }

    private function catalogModel(array $model, array $sensitive): ?array
    {
        if (! is_string($model['id'] ?? null)) {
            return null;
        }
        $id = trim($model['id']);
        if ($id === '' || strlen($id) > 160 || ! preg_match('/^[A-Za-z0-9._\/:\-]+$/', $id)
            || str_contains($id, '://') || $this->containsSensitiveMetadata($id, $sensitive)) {
            return null;
        }

        $model = MediaModelConfig::catalogModel($model);
        $category = is_string($model['category'] ?? null) ? strtolower(trim($model['category'])) : 'chat';
        $category = match ($category) {
            'image', 'images', 'canva' => 'image',
            'video', 'audio', 'embedding', 'embeddings' => rtrim($category, 's'),
            default => 'chat',
        };
        $tier = is_string($model['tier'] ?? null) ? $this->safeText($model['tier'], 40, $sensitive) : null;
        $provider = $model['provider'] ?? $model['owned_by'] ?? null;
        $name = is_string($model['name'] ?? null) ? $model['name'] : $id;
        $context = filter_var($model['context_length'] ?? $model['context_window'] ?? null, FILTER_VALIDATE_INT);
        $outputLimit = filter_var($model['max_output_tokens'] ?? null, FILTER_VALIDATE_INT);

        return [
            'id' => $id,
            'name' => $this->safeText($name, 160, $sensitive),
            'category' => $category,
            'tier' => $tier !== '' ? $tier : null,
            'capabilities' => $this->safeCapabilities($model['capabilities'] ?? [], $sensitive),
            'provider' => is_string($provider) ? $this->safeText($provider, 120, $sensitive) : null,
            'context_length' => $context !== false && $context > 0 && $context <= 10000000 ? $context : null,
            'max_output_tokens' => $outputLimit !== false && $outputLimit > 0 && $outputLimit <= 10000000 ? $outputLimit : null,
            'input_modalities' => $this->safeCapabilities($model['input_modalities'] ?? ['text'], $sensitive),
            'output_modalities' => $this->safeCapabilities($model['output_modalities'] ?? ['text'], $sensitive),
        ];
    }

    private function safeCapabilities(mixed $capabilities, array $sensitive): array
    {
        if (! is_array($capabilities)) {
            return [];
        }

        $safe = [];
        foreach ($capabilities as $key => $value) {
            if (is_string($key) && $this->isSensitiveMetadataName($key)) {
                continue;
            }

            if (is_array($value)) {
                $safe = [...$safe, ...$this->safeCapabilities($value, $sensitive)];

                continue;
            }

            $candidate = is_string($value)
                ? trim($value)
                : (is_string($key) && $value === true ? trim($key) : '');
            if ($candidate === '' || strlen($candidate) > 64 || str_contains($candidate, '://')) {
                continue;
            }
            if ($this->isSensitiveMetadataName($candidate) || $this->containsSensitiveMetadata($candidate, $sensitive)) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]*$/', $candidate) !== 1) {
                continue;
            }

            $safe[] = $candidate;
        }

        return array_values(array_unique($safe));
    }

    private function isSensitiveMetadataName(string $value): bool
    {
        return preg_match('/(?:api[_. -]?key|secret|password|credential|endpoint|base[_. -]?url|access[_. -]?token)/i', $value) === 1;
    }

    private function safeText(string $value, int $maximumLength, array $sensitive): string
    {
        $value = str_replace($sensitive, '[redacted]', strip_tags($value));
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return mb_substr($value, 0, $maximumLength);
    }

    private function containsSensitiveMetadata(string $value, array $sensitive): bool
    {
        foreach ($sensitive as $secret) {
            if (str_contains($value, $secret)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeModels(array $models): array
    {
        return array_values(array_filter($models, static function ($model): bool {
            if (! is_array($model) || ! is_string($model['id'] ?? null)) {
                return false;
            }
            $id = strtolower(trim($model['id']));
            $name = is_string($model['name'] ?? null) ? strtolower(trim($model['name'])) : '';
            $identity = strtolower(implode(' ', [
                $id,
                $name,
                is_string($model['provider'] ?? null) ? $model['provider'] : '',
                is_string($model['owned_by'] ?? null) ? $model['owned_by'] : '',
            ]));

            if ($id === '' || in_array($id, self::BLOCKED_MODEL_IDS, true) || in_array($name, self::BLOCKED_MODEL_NAMES, true)) {
                return false;
            }
            foreach (self::BLOCKED_MODEL_FRAGMENTS as $fragment) {
                if (str_contains($identity, $fragment)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Scrub model data with full info (includes media_type for full chat page)
     */
    private function scrubModelFull(array $model, array $tierMap = []): array
    {
        $tier = $model['tier'] ?? 'Standard';

        return [
            'id' => $model['id'] ?? 'unknown',
            'name' => $this->scrubText($model['name'] ?? $model['id'] ?? 'unknown'),
            'tier' => $tierMap[$tier] ?? 'Original',
            'category' => $model['category'] ?? 'chat',
        ];
    }

    private function scrubText(string $text): string
    {
        $text = str_ireplace(self::BLOCKED_MODEL_FRAGMENTS, 'XSuper.ai', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
