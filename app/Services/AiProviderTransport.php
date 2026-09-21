<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Models\AiProviderProfile;
use Generator;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Throwable;

final class AiProviderTransport
{
    private const CATALOG_PAGE_LIMIT = 100;

    private const CATALOG_MAX_PAGES = 100;

    private const OPENAI_OPTIONS = [
        'model', 'messages', 'stream', 'stream_options', 'tools', 'tool_choice', 'temperature', 'top_p',
        'max_tokens', 'max_completion_tokens', 'frequency_penalty', 'presence_penalty', 'stop', 'n',
        'response_format', 'seed', 'logit_bias', 'logprobs', 'top_logprobs', 'parallel_tool_calls',
        'reasoning_effort', 'service_tier', 'user', 'metadata', 'modalities', 'audio', 'prediction',
    ];

    public function __construct(private readonly AiProviderEndpoint $endpoint) {}

    /** @return array<int, array<string, mixed>> */
    public function catalog(?AiProviderProfile $provider = null): array
    {
        $connection = $this->connection($provider);
        if ($connection['protocol'] === 'fal') {
            return $this->falCatalog($connection);
        }
        if ($connection['protocol'] === 'anthropic') {
            return $this->anthropicCatalog($connection);
        }
        if ($connection['protocol'] === 'kinovi') {
            return KinoviProtocol::catalog();
        }

        $response = $this->send('GET', $connection['base_url'].'/models', $connection, timeout: 10);
        $models = $response->json('data');
        if (! is_array($models)) {
            throw new AiProxyException('The AI provider returned an invalid catalog.', 502);
        }

        return array_values(array_filter($models, 'is_array'));
    }

    /** @return array<string, mixed> */
    public function complete(?AiProviderProfile $provider, array $payload): array
    {
        $connection = $this->connection($provider);
        if ($connection['protocol'] === 'fal') {
            $request = FalProtocol::chatRequest($payload);
            $response = $this->send('POST', $connection['base_url'].'/'.FalProtocol::ROUTER, $connection, $request);
            $data = $response->json();
            if (! is_array($data)) {
                throw new AiProxyException('The fal chat provider returned an invalid response.', 502);
            }

            return FalProtocol::chatResponse($data, $request['model']);
        }
        if ($connection['protocol'] === 'anthropic') {
            $request = AnthropicProtocol::request([...$payload, 'stream' => false]);
            $response = $this->send('POST', $connection['base_url'].'/messages', $connection, $request, 120);
            $data = $response->json();
            if (! is_array($data)) {
                throw new AiProxyException('The AI provider returned an invalid response.', 502);
            }

            return AnthropicProtocol::response($data);
        }

        $request = $this->openAiPayload([...$payload, 'stream' => false]);
        $response = $this->send('POST', $connection['base_url'].'/chat/completions', $connection, $request, 120);
        $data = $response->json();
        if (! is_array($data) || ! is_array($data['choices'] ?? null) || $data['choices'] === []) {
            throw new AiProxyException('The AI provider returned an invalid response.', 502);
        }

        return $data;
    }

    /** @return Generator<int, array<string, mixed>> */
    public function stream(?AiProviderProfile $provider, array $payload): Generator
    {
        if ($provider?->base_url !== null && $provider?->protocol === 'fal') {
            // The prompt-only fal router is buffered: emit its verified result, never synthetic progress or usage.
            $response = $this->complete($provider, $payload);
            yield [
                'id' => $response['id'], 'object' => 'chat.completion.chunk',
                'created' => $response['created'], 'model' => $response['model'],
                'choices' => [['index' => 0, 'delta' => $response['choices'][0]['message'], 'finish_reason' => $response['choices'][0]['finish_reason']]],
                'usage' => $response['usage'],
            ];

            return;
        }
        $connection = $this->connection($provider);
        if ($connection['protocol'] === 'anthropic') {
            $request = AnthropicProtocol::request([...$payload, 'stream' => true]);
        } else {
            $streamOptions = $payload['stream_options'] ?? [];
            if ($streamOptions instanceof \stdClass) {
                $streamOptions = (array) $streamOptions;
            }
            if (! is_array($streamOptions) || (array_is_list($streamOptions) && $streamOptions !== [])) {
                throw new AiProxyException('The requested stream options are not supported by this AI provider.', 422);
            }
            $request = $this->openAiPayload([
                ...$payload,
                'stream' => true,
                'stream_options' => [...$streamOptions, 'include_usage' => true],
            ]);
        }

        $response = $this->send(
            'POST',
            $connection['base_url'].($connection['protocol'] === 'anthropic' ? '/messages' : '/chat/completions'),
            $connection,
            $request,
            120,
            true,
        );
        $body = $response->toPsrResponse()->getBody();

        try {
            yield from $connection['protocol'] === 'anthropic'
                ? ProviderSseStream::anthropic($body)
                : ProviderSseStream::openAi($body);
        } catch (AiProxyException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AiProxyException('The AI provider stream failed.', 502);
        } finally {
            $body->close();
        }
    }

    /** @return array<string, mixed> */
    public function imageGeneration(?AiProviderProfile $provider, array $payload, string $path = 'images/generations'): array
    {
        $connection = $this->connection($provider);
        if ($connection['protocol'] === 'fal') {
            $request = FalProtocol::imageRequest($payload);
            $response = $this->send('POST', $connection['base_url'].'/'.$payload['model'], $connection, $request, image: true);
            $data = $response->json();
            if (! is_array($data)) {
                throw new AiProxyException('The fal image provider returned an invalid response.', 502);
            }

            return FalProtocol::imageResponse($data);
        }
        if ($connection['protocol'] === 'kinovi') {
            $request = KinoviProtocol::imageTask($payload);
            $submit = $this->send('POST', $connection['base_url'].'/jobs/createTask', $connection, $request, 30, image: true);
            $taskId = $submit->json('taskId');
            if (! is_string($taskId) || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $taskId) !== 1) {
                throw new AiProxyException('The Kinovi provider did not return a valid task reference.', 502);
            }
            $deadline = microtime(true) + 100;
            do {
                usleep(2_500_000);
                $status = $this->send('GET', $connection['base_url'].'/jobs/recordInfo', $connection, timeout: 20, query: ['taskId' => $taskId], image: true);
                $data = $status->json();
                $state = is_array($data) ? ($data['status'] ?? null) : null;
                if ($state === 'success') {
                    return KinoviProtocol::imageResponse($data);
                }
                if ($state === 'fail') {
                    throw new AiProxyException('The Kinovi image provider could not complete this request.', 502);
                }
            } while (microtime(true) < $deadline);
            throw new AiProxyException('The Kinovi image provider timed out.', 504);
        }
        if ($connection['protocol'] !== 'openai') {
            throw new AiProxyException('Image generation is not supported by this AI provider.', 422);
        }
        if (! is_string($payload['model'] ?? null) || trim($payload['model']) === ''
            || ! is_string($payload['prompt'] ?? null) || trim($payload['prompt']) === '') {
            throw new AiProxyException('The image generation request is invalid.', 422);
        }

        $response = $this->send('POST', $connection['base_url'].'/'.MediaModelConfig::path($path), $connection, $payload, 120, image: true);
        $data = $response->json();
        if (! is_array($data) || ! is_array($data['data'] ?? null)) {
            throw new AiProxyException('The AI image provider returned an invalid response.', 502);
        }

        return $data;
    }

    public function submitVideo(AiProviderProfile $provider, array $payload, string $path): array
    {
        $connection = $this->connection($provider);
        if ($connection['protocol'] === 'fal') {
            $request = FalProtocol::videoRequest($payload);
            $response = $this->send('POST', 'https://queue.fal.run/'.$payload['model'], $connection, $request, 90);
            $data = $response->json();
            if (! is_array($data) || ! is_string($data['request_id'] ?? null)
                || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $data['request_id']) !== 1) {
                throw new AiProxyException('The fal video provider did not return a valid job reference.', 502);
            }

            return ['id' => $data['request_id'], 'status' => 'queued'];
        }
        if ($connection['protocol'] !== 'openai') {
            throw new AiProxyException('Video generation is not supported by this provider.', 422);
        }
        if (($payload['pro_mode'] ?? false) !== false || isset($payload['image_url'])) {
            throw new AiProxyException('Pro and reference images are not supported by this video provider.', 422);
        }
        unset($payload['pro_mode'], $payload['image_url']);
        $response = $this->send('POST', $connection['base_url'].'/'.MediaModelConfig::path($path), $connection, $payload, 90);
        $data = $response->json();
        if (! is_array($data)) {
            throw new AiProxyException('The video provider returned an invalid response.', 502);
        }

        return $data;
    }

    public function videoStatus(AiProviderProfile $provider, string $taskId, string $path): array
    {
        $connection = $this->connection($provider);
        if ($connection['protocol'] === 'fal') {
            if ($path !== FalProtocol::VIDEO_REQUEST_PATH) {
                throw new AiProxyException('The fal video status configuration is invalid.', 502);
            }
            $url = 'https://queue.fal.run/'.MediaModelConfig::path($path, $taskId);
            $response = $this->send('GET', $url.'/status', $connection, timeout: 20);
            $data = $response->json();
            if (! is_array($data) || ! in_array($data['status'] ?? null, ['IN_QUEUE', 'IN_PROGRESS', 'COMPLETED'], true)) {
                throw new AiProxyException('The fal video provider returned an invalid status.', 502);
            }
            if (! empty($data['error']) || ! empty($data['error_type'])) {
                return ['status' => 'failed'];
            }
            if ($data['status'] !== 'COMPLETED') {
                return ['status' => 'processing'];
            }
            $result = $this->send('GET', $url, $connection, timeout: 20)->json();
            $videoUrl = is_array($result) ? data_get($result, 'video.url') : null;
            if (! is_string($videoUrl) || ! GeneratedImageStore::validResultUrl($videoUrl)) {
                throw new AiProxyException('The fal video provider returned an invalid result.', 502);
            }

            return ['status' => 'completed', 'video_url' => $videoUrl];
        }
        $response = $this->send('GET', $connection['base_url'].'/'.MediaModelConfig::path($path, $taskId), $connection, timeout: 20);
        $data = $response->json();
        if (! is_array($data)) {
            throw new AiProxyException('The video provider returned an invalid status.', 502);
        }

        return $data;
    }

    public function submitAudio(AiProviderProfile $provider, array $payload, string $path): array
    {
        $connection = $this->connection($provider);
        if ($connection['protocol'] !== 'fal') {
            throw new AiProxyException('Audio generation is not supported by this provider.', 422);
        }
        $request = FalProtocol::audioRequest($payload);
        if ($path !== $payload['model']) {
            throw new AiProxyException('The fal audio generation configuration is invalid.', 502);
        }
        $data = $this->send('POST', 'https://queue.fal.run/'.$path, $connection, $request, 90)->json();
        if (! is_array($data) || ! is_string($data['request_id'] ?? null)
            || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $data['request_id']) !== 1) {
            throw new AiProxyException('The fal audio provider did not return a valid job reference.', 502);
        }

        return ['id' => $data['request_id'], 'status' => 'queued'];
    }

    public function audioStatus(AiProviderProfile $provider, string $taskId, string $path): array
    {
        $connection = $this->connection($provider);
        if ($connection['protocol'] !== 'fal'
            || ! in_array($path, [FalProtocol::AUDIO_SPEECH_REQUEST_PATH, FalProtocol::AUDIO_MUSIC_REQUEST_PATH], true)
            || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $taskId) !== 1) {
            throw new AiProxyException('The audio status configuration is invalid.', 502);
        }
        $url = 'https://queue.fal.run/'.MediaModelConfig::path($path, $taskId);
        $data = $this->send('GET', $url.'/status', $connection, timeout: 20)->json();
        if (! is_array($data) || ! in_array($data['status'] ?? null, ['IN_QUEUE', 'IN_PROGRESS', 'COMPLETED'], true)) {
            throw new AiProxyException('The fal audio provider returned an invalid status.', 502);
        }
        if (! empty($data['error']) || ! empty($data['error_type'])) {
            return ['status' => 'failed'];
        }
        if ($data['status'] !== 'COMPLETED') {
            return ['status' => 'processing'];
        }
        $result = $this->send('GET', $url, $connection, timeout: 20)->json();
        if (is_array($result) && (! empty($result['error']) || ! empty($result['error_type']))) {
            return ['status' => 'failed'];
        }
        $audio = is_array($result) ? ($result['audio'] ?? null) : null;
        $audioUrl = is_array($audio) ? ($audio['url'] ?? null) : $audio;
        if (! is_string($audioUrl) || ! GeneratedImageStore::validResultUrl($audioUrl)) {
            throw new AiProxyException('The fal audio provider returned an invalid result.', 502);
        }

        return ['status' => 'completed', 'audio_url' => $audioUrl];
    }

    private function falCatalog(array $connection): array
    {
        $pricing = $this->send('GET', 'https://api.fal.ai/v1/models/pricing', $connection, timeout: 15, query: ['endpoint_id' => FalProtocol::IMAGE_SCHNELL])->json();
        $prices = is_array($pricing) ? ($pricing['prices'] ?? null) : null;
        $authenticated = is_array($prices) && collect($prices)->contains(static fn ($price): bool => is_array($price)
            && ($price['endpoint_id'] ?? null) === FalProtocol::IMAGE_SCHNELL
            && is_numeric($price['unit_price'] ?? null) && $price['unit_price'] >= 0
            && is_string($price['unit'] ?? null) && is_string($price['currency'] ?? null));
        if (! $authenticated) {
            throw new AiProxyException('The fal provider returned invalid authentication metadata.', 502);
        }

        $models = [];
        foreach ([...FalProtocol::MEDIA_MODELS, FalProtocol::ROUTER => 'chat'] as $id => $category) {
            $data = $this->send('GET', 'https://api.fal.ai/v1/models', $connection, timeout: 15, query: ['endpoint_id' => $id])->json();
            if (! is_array($data) || ! is_array($data['models'] ?? null)) {
                throw new AiProxyException('The fal provider returned an invalid catalog.', 502);
            }
            foreach ($data['models'] as $entry) {
                if (! is_array($entry) || ($entry['endpoint_id'] ?? null) !== $id || data_get($entry, 'metadata.status') !== 'active') {
                    continue;
                }
                $chat = $id === FalProtocol::ROUTER;
                $models[] = [
                    'id' => $chat ? FalProtocol::CHAT_MODEL : $id,
                    'name' => $chat ? 'Gemini 2.5 Flash Lite' : (data_get($entry, 'metadata.display_name') ?: $id),
                    'category' => $category,
                    'capabilities' => $chat ? ['chat', 'text-only', 'buffered-stream'] : array_values(array_filter([
                        $category, $category === 'audio' ? FalProtocol::mediaConfig($id)['audio_kind'] : null,
                    ])),
                    'input_modalities' => in_array($id, [FalProtocol::VIDEO, FalProtocol::VIDEO_REFERENCE], true) ? ['text', 'image'] : ['text'],
                    'output_modalities' => [$chat ? 'text' : $category],
                ];
            }
        }

        return $models;
    }

    /**
     * @param  array{protocol: string, base_url: string, key: string, version: string, options: array<string, mixed>, saved: bool}  $connection
     * @return array<int, array<string, mixed>>
     */
    private function anthropicCatalog(array $connection): array
    {
        $models = [];
        $after = null;
        $seenCursors = [];
        for ($page = 0; $page < self::CATALOG_MAX_PAGES; $page++) {
            $query = ['limit' => self::CATALOG_PAGE_LIMIT];
            if ($after !== null) {
                $query['after_id'] = $after;
            }
            $response = $this->send('GET', $connection['base_url'].'/models', $connection, query: $query, timeout: 10);
            $data = $response->json();
            if (! is_array($data) || ! is_array($data['data'] ?? null)) {
                throw new AiProxyException('The AI provider returned an invalid catalog.', 502);
            }
            foreach ($data['data'] as $model) {
                if (! is_array($model)) {
                    throw new AiProxyException('The AI provider returned an invalid catalog.', 502);
                }
                $models[] = $this->anthropicModel($model);
            }
            if (($data['has_more'] ?? false) === false) {
                return $models;
            }
            $cursor = $data['last_id'] ?? null;
            if (! is_string($cursor) || $cursor === '' || isset($seenCursors[$cursor])) {
                throw new AiProxyException('The AI provider returned an invalid catalog.', 502);
            }
            $seenCursors[$cursor] = true;
            $after = $cursor;
        }

        throw new AiProxyException('The AI provider returned an invalid catalog.', 502);
    }

    /** @return array<string, mixed> */
    private function anthropicModel(array $model): array
    {
        $id = $model['id'] ?? null;
        if (! is_string($id) || trim($id) === '') {
            throw new AiProxyException('The AI provider returned an invalid catalog.', 502);
        }
        $capabilities = $model['capabilities'] ?? [];
        $normalizedCapabilities = [];
        if (is_array($capabilities)) {
            foreach ($capabilities as $key => $value) {
                if (is_string($value) && trim($value) !== '') {
                    $normalizedCapabilities[] = trim($value);
                } elseif (is_string($key) && $value === true) {
                    $normalizedCapabilities[] = $key;
                } elseif (is_string($key) && is_array($value) && ($value['supported'] ?? false) === true) {
                    $normalizedCapabilities[] = match ($key) {
                        'image_input', 'vision' => 'vision',
                        'tool_use', 'tools' => 'tools',
                        default => $key,
                    };
                }
            }
        }
        $normalizedCapabilities = array_values(array_unique($normalizedCapabilities));
        $inputModalities = ['text'];
        if (in_array('vision', $normalizedCapabilities, true) || in_array('image', $normalizedCapabilities, true)) {
            $inputModalities[] = 'image';
        }

        return [
            ...$model,
            'id' => $id,
            'name' => is_string($model['display_name'] ?? null) && $model['display_name'] !== '' ? $model['display_name'] : $id,
            'category' => 'chat',
            'capabilities' => $normalizedCapabilities,
            'context_length' => $this->positiveInteger($model['max_input_tokens'] ?? $model['context_window'] ?? null),
            'max_output_tokens' => $this->positiveInteger($model['max_tokens'] ?? $model['max_output_tokens'] ?? null),
            'input_modalities' => $inputModalities,
            'output_modalities' => ['text'],
        ];
    }

    /** @return array<string, mixed> */
    private function openAiPayload(array $payload): array
    {
        foreach (array_keys($payload) as $option) {
            if (! in_array($option, self::OPENAI_OPTIONS, true)) {
                throw new AiProxyException('The requested '.$option.' is not supported by this AI provider.', 422);
            }
        }
        if (! is_string($payload['model'] ?? null) || trim($payload['model']) === ''
            || ! is_array($payload['messages'] ?? null) || $payload['messages'] === []) {
            throw new AiProxyException('The AI completion request is invalid.', 422);
        }

        return array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array{protocol: string, base_url: string, key: string, version: string, options: array<string, mixed>, saved: bool}
     */
    private function connection(?AiProviderProfile $provider): array
    {
        $saved = $provider?->base_url !== null;
        $protocol = $saved ? strtolower(trim((string) $provider->protocol)) : 'openai';
        if (! in_array($protocol, ['openai', 'anthropic', 'fal', 'kinovi'], true)) {
            throw new AiProxyException('The AI provider configuration is invalid.', 503);
        }
        $baseUrl = $saved ? (string) $provider->base_url : (string) config('services.ai_proxy.url', '');
        $key = $saved ? (string) $provider->api_key : (string) config('services.ai_proxy.key', '');
        if (trim($baseUrl) === '' || trim($key) === '') {
            throw new AiProxyException('The AI provider is unavailable.', 503);
        }

        try {
            if ($saved) {
                $baseUrl = $this->endpoint->normalize($baseUrl, $protocol);
                $options = $protocol === 'fal' ? ['allow_redirects' => false] : $this->endpoint->requestOptions($baseUrl);
            } else {
                if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
                    throw new AiProxyException('The AI provider is unavailable.', 503);
                }
                $baseUrl = rtrim($baseUrl, '/');
                if (! str_ends_with(strtolower((string) parse_url($baseUrl, PHP_URL_PATH)), '/v1')) {
                    $baseUrl .= '/v1';
                }
                $options = ['allow_redirects' => false];
            }
        } catch (AiProxyException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AiProxyException('The AI provider configuration is invalid.', 503);
        }

        return [
            'protocol' => $protocol,
            'base_url' => rtrim($baseUrl, '/'),
            'key' => $key,
            'version' => $saved && is_string($provider->api_version) && trim($provider->api_version) !== ''
                ? trim($provider->api_version)
                : '2023-06-01',
            'options' => $options,
            'saved' => $saved,
        ];
    }

    /**
     * @param  array{protocol: string, base_url: string, key: string, version: string, options: array<string, mixed>, saved: bool}  $connection
     * @param  array<string, mixed>|null  $json
     * @param  array<string, mixed>  $query
     */
    private function send(
        string $method,
        string $url,
        array $connection,
        ?array $json = null,
        int $timeout = 120,
        bool $stream = false,
        array $query = [],
        bool $image = false,
    ): Response {
        if ($connection['protocol'] === 'fal') {
            $host = parse_url($url, PHP_URL_HOST);
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array($host, ['fal.run', 'queue.fal.run', 'api.fal.ai'], true)) {
                throw new AiProxyException('The fal provider destination is invalid.', 503);
            }
            $connection['options'] = $this->endpoint->requestOptions('https://'.$host);
        }
        if ($stream && $connection['saved']) {
            [$host, $port, $ip] = $this->pinnedStreamTarget($connection);
            $connection['stream_target'] = [$host, $port, $ip];
        }
        $request = $this->request($connection, $timeout, $stream);
        try {
            $response = $request->send($method, $url, array_filter([
                'json' => $json,
                'query' => $query !== [] ? $query : null,
            ], static fn (mixed $value): bool => $value !== null));
        } catch (ConnectionException) {
            throw new AiProxyException($image ? 'The AI image provider is unavailable.' : 'The AI provider is unavailable.', 503);
        } catch (Throwable) {
            throw new AiProxyException($image ? 'The AI image provider is unavailable.' : 'The AI provider is unavailable.', 503);
        }

        if (! $response->successful()) {
            if ($image && in_array($response->status(), [404, 405, 501], true)) {
                throw new AiProxyException('Image generation is not supported by the AI provider.', 502);
            }
            $unavailable = $response->status() === 429 || $response->serverError();
            throw new AiProxyException(
                $unavailable
                    ? ($image ? 'The AI image provider is unavailable.' : 'The AI provider is unavailable.')
                    : ($image ? 'The AI image provider rejected the request.' : 'The AI provider rejected the request.'),
                $unavailable ? 503 : 502,
            );
        }

        return $response;
    }

    /**
     * @param  array{protocol: string, base_url: string, key: string, version: string, options: array<string, mixed>, saved: bool, stream_target?: array{string, int, string}}  $connection
     */
    private function request(array $connection, int $timeout, bool $stream): PendingRequest
    {
        $headers = match ($connection['protocol']) {
            'anthropic' => ['x-api-key' => $connection['key'], 'anthropic-version' => $connection['version']],
            'fal' => ['Authorization' => 'Key '.$connection['key']],
            default => ['Authorization' => 'Bearer '.$connection['key']],
        };
        $request = Http::withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout(10)
            ->withOptions([...$connection['options'], 'stream' => $stream]);

        if ($stream && $connection['saved']) {
            [$host, $port, $ip] = $connection['stream_target'];
            $streamHandler = new StreamHandler;
            $request->withOptions([
                'stream_context' => ['ssl' => [
                    'peer_name' => $host,
                    'SNI_enabled' => true,
                    'SNI_server_name' => $host,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ]],
            ])->setHandler(static function (RequestInterface $psrRequest, array $options) use ($host, $port, $ip, $streamHandler): PromiseInterface {
                $uriHost = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.$ip.']' : $ip;
                $authorityHost = str_contains($host, ':') ? '['.$host.']' : $host;
                $authority = $authorityHost.($port === 443 ? '' : ':'.$port);
                $pinnedRequest = $psrRequest
                    ->withUri($psrRequest->getUri()->withHost($uriHost), true)
                    ->withHeader('Host', $authority);

                return $streamHandler($pinnedRequest, $options);
            });
        }

        return $request;
    }

    /**
     * @param  array{protocol: string, base_url: string, key: string, version: string, options: array<string, mixed>, saved: bool}  $connection
     * @return array{string, int, string}
     */
    private function pinnedStreamTarget(array $connection): array
    {
        $host = strtolower((string) parse_url($connection['base_url'], PHP_URL_HOST));
        $port = (int) (parse_url($connection['base_url'], PHP_URL_PORT) ?: 443);
        $resolutions = $connection['options']['curl'][CURLOPT_RESOLVE] ?? null;
        if ($host === '' || ! is_array($resolutions) || $resolutions === []) {
            throw new AiProxyException('The AI provider is unavailable.', 503);
        }

        $prefix = $host.':'.$port.':';
        foreach ($resolutions as $resolution) {
            if (! is_string($resolution) || ! str_starts_with(strtolower($resolution), strtolower($prefix))) {
                continue;
            }
            $targets = explode(',', substr($resolution, strlen($prefix)));
            foreach ($targets as $target) {
                $ip = trim(trim($target), '[]');
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return [$host, $port, $ip];
                }
            }
        }

        throw new AiProxyException('The AI provider is unavailable.', 503);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
