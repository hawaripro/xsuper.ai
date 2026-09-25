<?php

namespace App\Services;

use App\Exceptions\AiProviderNotSent;
use App\Exceptions\AiProviderRequestRejected;
use App\Exceptions\AiProxyException;
use App\Media\FalCapabilityImporter;
use App\Models\AiProviderProfile;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Stream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
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
        if ($connection['protocol'] === 'runware') {
            // Runware models are schema contracts imported by catalog discovery; the key is verified by the account check.
            throw new AiProxyException('Runware models are imported through catalog discovery, not a catalog sync.', 422);
        }

        $response = $this->send('GET', $connection['base_url'].'/models', $connection, timeout: 10);
        $models = $response->json('data');
        if (! is_array($models)) {
            throw new AiProxyException('The AI provider returned an invalid catalog.', 502);
        }

        return array_values(array_filter($models, 'is_array'));
    }

    /** One schema-expanded, bounded page. Public catalog access is not credential verification. */
    public function discoverFalPage(AiProviderProfile $provider, ?string $cursor = null, int $limit = 10): array
    {
        $connection = $this->connection($provider);
        if ($connection['protocol'] !== 'fal' || $limit < 1 || $limit > 10
            || ($cursor !== null && (strlen($cursor) > 2048 || preg_match('/^[A-Za-z0-9+\/_=\\-]+$/D', $cursor) !== 1))) {
            throw new AiProxyException('Schema discovery requires a configured fal provider and a valid bounded page.', 422);
        }
        $rateKey = 'fal-discovery:'.$provider->id;
        if (RateLimiter::tooManyAttempts($rateKey, 20)) {
            throw new AiProxyException('Catalog discovery is rate limited. Try again shortly.', 429);
        }
        RateLimiter::hit($rateKey, 60);
        $response = $this->send('GET', 'https://api.fal.ai/v1/models', $connection, timeout: 20,
            query: array_filter(['expand' => 'openapi-3.0', 'limit' => $limit, 'cursor' => $cursor], fn ($value) => $value !== null),
            catalogRetry: true);
        if (strlen($response->body()) > 16 * 1024 * 1024) {
            throw new AiProxyException('The catalog page is too large. Use a smaller page size.', 502);
        }
        $data = $response->json();
        if (! is_array($data) || ! is_array($data['models'] ?? null) || ! array_is_list($data['models']) || count($data['models']) > $limit) {
            throw new AiProxyException('The fal provider returned an invalid catalog page.', 502);
        }
        foreach ($data['models'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['endpoint_id'] ?? null)
                || ! FalCapabilityImporter::validEndpoint($entry['endpoint_id']) || ! is_array($entry['metadata'] ?? null)) {
                throw new AiProxyException('The fal provider returned an invalid model entry.', 502);
            }
        }
        $next = $data['next_cursor'] ?? null;
        if (($data['has_more'] ?? false) && (! is_string($next) || $next === '')
            || ($next !== null && (! is_string($next) || $next === $cursor || strlen($next) > 2048 || preg_match('/^[A-Za-z0-9+\/_=\\-]+$/D', $next) !== 1))) {
            throw new AiProxyException('The fal provider returned an invalid pagination cursor.', 502);
        }

        return ['models' => $data['models'], 'next_cursor' => $next];
    }

    /** Executed only from a reviewed, immutable image capability binding. Never retries a paid POST. */
    public function runCatalogImage(AiProviderProfile $provider, string $endpoint, array $payload): array
    {
        $connection = $this->connection($provider);
        if ($connection['protocol'] !== 'fal' || ! FalCapabilityImporter::validEndpoint($endpoint)) {
            throw new AiProxyException('The image capability routing is invalid.', 422);
        }
        $response = $this->send('POST', $connection['base_url'].'/'.$endpoint, $connection, $payload, image: true);
        $data = $response->json();
        if (! is_array($data) || ! is_array($data['images'] ?? null) || count($data['images']) > 10) {
            throw new AiProxyException('The image provider returned an invalid result.', 502);
        }

        return FalProtocol::imageResponse($data);
    }

    /** Official SDK parseEndpointId semantics: inference suffixes are not queue application roots. */
    public static function falQueueRoot(string $endpoint): string
    {
        if (! FalCapabilityImporter::validEndpoint($endpoint)) {
            throw new AiProxyException('The media capability routing is invalid.', 422);
        }
        $parts = explode('/', $endpoint);
        $length = in_array($parts[0], ['workflows', 'comfy'], true) ? 3 : 2;
        if (count($parts) < $length) {
            throw new AiProxyException('The media capability routing is invalid.', 422);
        }

        return implode('/', array_slice($parts, 0, $length));
    }

    /** One paid POST only. Missing acknowledgement remains unknown acceptance, never a safe rejection. */
    public function submitFalQueue(AiProviderProfile $provider, string $endpoint, array $payload, array $bindings = []): array
    {
        $connection = $this->connection($provider);
        $this->falQueueContext($connection, [...$bindings, 'endpoint' => $endpoint]);
        $data = $this->send('POST', $this->falBase($connection).'/'.$endpoint, $connection, $payload, 90)->json();
        if (! is_array($data) || ! is_string($data['request_id'] ?? null)
            || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $data['request_id']) !== 1) {
            throw new AiProxyException('The media provider acceptance could not be confirmed.', 504);
        }

        return ['request_id' => $data['request_id']];
    }

    public function falQueueStatus(AiProviderProfile $provider, string $taskId, array $bindings): array
    {
        $connection = $this->connection($provider);
        $url = $this->falQueueRequestUrl($connection, $taskId, $bindings);
        $data = $this->send('GET', $url.'/status', $connection, timeout: 20, query: ['logs' => '0'])->json();
        if (! is_array($data) || ! in_array($data['status'] ?? null, ['IN_QUEUE', 'IN_PROGRESS', 'COMPLETED'], true)) {
            throw new AiProxyException('The media provider returned an invalid status.', 502);
        }
        if (! empty($data['error']) || ! empty($data['error_type'])) {
            return ['status' => 'FAILED'];
        }

        return ['status' => $data['status']];
    }

    /** Preserve the complete JSON value. File discovery and private persistence belong to the output store. */
    public function falQueueResult(AiProviderProfile $provider, string $taskId, array $bindings): mixed
    {
        $connection = $this->connection($provider);
        $response = $this->send('GET', $this->falQueueRequestUrl($connection, $taskId, $bindings), $connection, timeout: 60);
        try {
            return json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiProxyException('The media provider returned an invalid result.', 502);
        }
    }

    /** The documented queue API acknowledges a request, not guaranteed interruption or refundable work. */
    public function cancelFalQueue(AiProviderProfile $provider, string $taskId, array $bindings): array
    {
        $connection = $this->connection($provider);
        $this->send('PUT', $this->falQueueRequestUrl($connection, $taskId, $bindings).'/cancel', $connection, timeout: 20);

        return ['requested' => true, 'confirmed' => false];
    }

    /** A reviewed direct Fal contract, not a native image request or a synthetic queue task. */
    public function runFalDirect(AiProviderProfile $provider, string $endpoint, array $payload): mixed
    {
        $connection = $this->connection($provider);
        if ($connection['protocol'] !== 'fal' || ! FalCapabilityImporter::validEndpoint($endpoint)) {
            throw new AiProxyException('The media capability routing is invalid.', 422);
        }
        $response = $this->send('POST', $connection['base_url'].'/'.$endpoint, $connection, $payload, 120);
        try {
            return json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiProxyException('The provider response could not be confirmed.', 504);
        }
    }

    /** Official WMA bridge only. Session admission/leases/billing remain the caller's responsibility. */
    public function postFalRealtime(AiProviderProfile $provider, string $path, array $payload): array
    {
        $timeout = match ($path) {
            '/session' => 120,
            '/session/heartbeat' => 4,
            '/ice' => 5,
            default => throw new AiProxyException('The realtime provider route is invalid.', 422),
        };
        $connection = $this->connection($provider);
        if ($connection['protocol'] !== 'fal') {
            throw new AiProxyException('Realtime sessions are not supported by this provider.', 422);
        }
        if ($path === '/session/heartbeat') {
            if (array_keys($payload) !== ['session_id'] || ! is_string($payload['session_id'])
                || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $payload['session_id']) !== 1) {
                throw new AiProxyException('The realtime session reference is invalid.', 422);
            }
        } else {
            if (! is_string($payload['app_id'] ?? null) || ! FalCapabilityImporter::validEndpoint($payload['app_id'])
                || array_diff(array_keys($payload), $path === '/ice' ? ['app_id'] : ['app_id', 'sdp', 'type']) !== []) {
                throw new AiProxyException('The realtime provider request is invalid.', 422);
            }
            if ($path === '/session' && (($payload['type'] ?? null) !== 'offer'
                || ! is_string($payload['sdp'] ?? null) || trim($payload['sdp']) === '' || strlen($payload['sdp']) > 262144)) {
                throw new AiProxyException('The realtime session offer is invalid.', 422);
            }
        }
        $data = $this->send('POST', 'https://wma.fal.run'.$path, $connection, $payload, $timeout)->json();
        if (! is_array($data)) {
            throw new AiProxyException('The realtime provider returned an invalid response.', $path === '/session' ? 504 : 502);
        }

        return $data;
    }

    private function falQueueContext(array $connection, array $bindings): string
    {
        if ($connection['protocol'] !== 'fal' || ! is_string($bindings['endpoint'] ?? null)
            || ($bindings['transport'] ?? 'queue') !== 'queue') {
            throw new AiProxyException('The media capability routing is invalid.', 422);
        }
        $root = self::falQueueRoot($bindings['endpoint']);
        if (isset($bindings['queue_root']) && $bindings['queue_root'] !== $root) {
            throw new AiProxyException('The media capability queue binding is invalid.', 422);
        }

        return $root;
    }

    private function falQueueRequestUrl(array $connection, string $taskId, array $bindings): string
    {
        if (preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $taskId) !== 1) {
            throw new AiProxyException('The media provider job reference is invalid.', 422);
        }

        return $this->falBase($connection).'/'.$this->falQueueContext($connection, $bindings).'/requests/'.rawurlencode($taskId);
    }

    /**
     * Official fal-js storage v3 single/multipart lifecycle, streaming bytes without base64.
     * The caller retains ownership of the readable resource. API keys never reach upload URLs.
     *
     * @param resource $stream
     */
    public function uploadFalReference(AiProviderProfile $provider, mixed $stream, string $fileName, string $mime, int $sizeBytes): string
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream' || $sizeBytes < 1
            || trim($fileName) === '' || strlen($fileName) > 255 || str_contains($fileName, '/') || str_contains($fileName, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $fileName)
            || preg_match('/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*$/iD', $mime) !== 1) {
            throw new AiProxyException('The reference upload is invalid.', 422);
        }
        $body = new Stream($stream, ['size' => $sizeBytes]);
        try {
            if (! $body->isReadable() || $body->tell() !== 0) {
                throw new AiProxyException('The reference upload is invalid.', 422);
            }
            $connection = $this->connection($provider);
            if ($connection['protocol'] !== 'fal') {
                throw new AiProxyException('Reference staging is not supported by this provider.', 422);
            }
            $multipart = $sizeBytes > 90 * 1024 * 1024;
            $upload = $this->send('POST', 'https://rest.fal.ai/storage/upload/'.($multipart ? 'initiate-multipart' : 'initiate'),
                $connection, ['file_name' => $fileName, 'content_type' => $mime], timeout: 30,
                query: ['storage_type' => 'fal-cdn-v3'], headers: ['X-Fal-Object-Lifecycle' => '{"expiration_duration_seconds":86400}'])->json();
            $options = $this->falStorageOptions($upload['upload_url'] ?? null);
            $this->falStorageOptions($upload['file_url'] ?? null);
            if (! $multipart) {
                $this->putFalReference($upload['upload_url'], $body, $mime, $sizeBytes, $options);
            } else {
                $parts = parse_url($upload['upload_url']);
                $base = 'https://'.$parts['host'].$parts['path'];
                $query = isset($parts['query']) ? '?'.$parts['query'] : '';
                $completed = [];
                $chunkSize = 10 * 1024 * 1024;
                for ($offset = 0, $number = 1; $offset < $sizeBytes; $offset += $chunkSize, $number++) {
                    $length = min($chunkSize, $sizeBytes - $offset);
                    $part = new LimitStream($body, $length, $offset);
                    $response = $this->putFalReference($base.'/'.$number.$query, $part, $mime, $length, $options);
                    $etag = $response->json('etag') ?? $response->header('ETag');
                    if (! is_string($etag) || trim($etag) === '' || strlen($etag) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $etag)) {
                        throw new AiProxyException('The reference upload part could not be confirmed.', 502);
                    }
                    $completed[] = ['partNumber' => $number, 'etag' => $etag];
                }
                $response = Http::acceptJson()->asJson()->timeout(30)->connectTimeout(10)
                    ->withOptions([...$options, 'cookies' => false, 'auth' => null])
                    ->post($base.'/complete'.$query, ['parts' => $completed]);
                if (! $response->successful()) {
                    throw new AiProxyException('The reference upload could not be completed.', 502);
                }
            }

            return $upload['file_url'];
        } catch (AiProxyException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AiProxyException('The reference upload failed.', 502);
        } finally {
            $body->detach();
        }
    }

    private function falStorageOptions(mixed $url): array
    {
        if (! is_string($url) || ! GeneratedImageStore::validResultUrl($url)) {
            throw new AiProxyException('The reference storage destination is invalid.', 502);
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host']);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['port'] ?? 443) !== 443 || ($parts['path'] ?? '') === ''
            || ! ($host === 'fal.media' || str_ends_with($host, '.fal.media') || $host === 'fal.ai' || str_ends_with($host, '.fal.ai'))) {
            throw new AiProxyException('The reference storage destination is invalid.', 502);
        }

        return $this->endpoint->requestOptions('https://'.$host);
    }

    private function putFalReference(string $url, StreamInterface $body, string $mime, int $sizeBytes, array $options): Response
    {
        $handler = new CurlHandler;
        $response = Http::withHeaders(['Content-Length' => (string) $sizeBytes])->withBody($body, $mime)
            ->timeout(120)->connectTimeout(10)->withOptions([...$options, 'stream' => false, 'cookies' => false, 'auth' => null])
            ->setHandler(static function (RequestInterface $request, array $options) use ($handler, $sizeBytes): PromiseInterface {
                $options['curl'][CURLOPT_INFILESIZE] = $sizeBytes;

                return $handler($request->withoutHeader('Content-Length'), $options);
            })->send('PUT', $url);
        if (! $response->successful()) {
            throw new AiProxyException('The reference upload failed.', 502);
        }

        return $response;
    }

    /**
     * One Runware REST call: a JSON array of tasks POSTed to the pinned endpoint, never retried.
     * Returns the envelope's `data` and `errors` (the singular `error` form is merged into `errors`).
     * HTTP 4xx (including 402 insufficient balance) is a rejection carrying the Runware error code;
     * 408/409/429, 5xx and transport failures are unknown outcomes.
     *
     * @param  list<array<string, mixed>>  $tasks
     * @return array{data: list<array<string, mixed>>, errors: list<array<string, mixed>>}
     */
    public function runwareTasks(AiProviderProfile $provider, array $tasks, int $timeoutSeconds = 60): array
    {
        try {
            $connection = $this->connection($provider);
            if ($connection['protocol'] !== 'runware' || $tasks === [] || ! array_is_list($tasks) || count($tasks) > 20
                || $timeoutSeconds < 1 || $timeoutSeconds > 300) {
                throw new AiProxyException('The Runware task request is invalid.', 422);
            }
            foreach ($tasks as $task) {
                if (! is_array($task) || array_is_list($task) || ! is_string($task['taskType'] ?? null)
                    || preg_match('/^[A-Za-z0-9]{1,64}$/D', $task['taskType']) !== 1
                    || ! is_string($task['taskUUID'] ?? null) || ! Str::isUuid($task['taskUUID'])) {
                    throw new AiProxyException('The Runware task request is invalid.', 422);
                }
            }
            if ($connection['base_url'] !== 'https://api.runware.ai/v1'
                && ! $this->endpoint->allowsLoopback((string) parse_url($connection['base_url'], PHP_URL_HOST))) {
                throw new AiProxyException('The Runware provider destination is invalid.', 503);
            }
        } catch (AiProxyException $exception) {
            // Nothing has been sent yet, so a paid task cannot have been accepted.
            throw new AiProviderNotSent($exception->getMessage(), $exception->responseStatus(), $exception);
        }
        $response = $this->send('POST', $connection['base_url'], $connection, $tasks, $timeoutSeconds, errorCodes: true);
        if (strlen($response->body()) > 16 * 1024 * 1024) {
            throw new AiProxyException('The Runware provider response is too large.', 502);
        }
        $body = $response->json();
        if (! is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new AiProxyException('The Runware provider returned an invalid response.', 502);
        }
        $envelope = [];
        foreach (['data' => $body['data'] ?? [], 'errors' => $body['errors'] ?? []] as $key => $items) {
            // A lone object is one item; anything else must be a list of objects.
            $items = is_array($items) && $items !== [] && ! array_is_list($items) ? [$items] : $items;
            if (! is_array($items) || array_filter($items, static fn (mixed $item): bool => ! is_array($item)) !== []) {
                throw new AiProxyException('The Runware provider returned an invalid response.', 502);
            }
            $envelope[$key] = array_values($items);
        }
        if (array_key_exists('error', $body)) {
            $envelope['errors'][] = is_array($body['error']) ? $body['error'] : ['message' => is_string($body['error']) ? $body['error'] : null];
        }

        return $envelope;
    }

    /**
     * accountManagement getDetails, reduced to balance and usage. Organization identity, team
     * members and API keys in the provider response are never returned or stored.
     *
     * @return array{balance: float, free_balance: float, currency: string, usage: array<string, array{credits: ?float, requests: ?int}>}
     */
    public function runwareAccount(AiProviderProfile $provider): array
    {
        $taskId = (string) Str::uuid();
        $result = $this->runwareTasks($provider, [['taskType' => 'accountManagement', 'taskUUID' => $taskId, 'operation' => 'getDetails']], 20);
        $item = collect($result['data'])->first(static fn (array $item): bool => ($item['taskUUID'] ?? $taskId) === $taskId);
        $balance = is_array($item) ? ($item['balance'] ?? null) : null;
        if ($result['errors'] !== [] || ! is_array($balance) || ! is_numeric($balance['amount'] ?? null)
            || ! is_string($balance['currency'] ?? null) || preg_match('/^[A-Z]{3}$/D', $balance['currency']) !== 1) {
            throw new AiProxyException('The Runware account details could not be read with this API key.', 502);
        }
        $usage = [];
        foreach (['today' => 'today', 'last_7_days' => 'last7Days', 'last_30_days' => 'last30Days'] as $period => $source) {
            $values = is_array($item['usage'][$source] ?? null) ? $item['usage'][$source] : [];
            $usage[$period] = [
                'credits' => is_numeric($values['credits'] ?? null) ? (float) $values['credits'] : null,
                'requests' => is_numeric($values['requests'] ?? null) ? (int) $values['requests'] : null,
            ];
        }

        return [
            'balance' => (float) $balance['amount'],
            // Documented with every balance; an absent value means no free credit was reported.
            'free_balance' => is_numeric($balance['freeBalance'] ?? null) ? (float) $balance['freeBalance'] : 0.0,
            'currency' => $balance['currency'],
            'usage' => $usage,
        ];
    }

    /** mediaStorage upload of one owned reference (a data URI or a signed, time-limited URL); returns its mediaUUID. */
    public function uploadRunwareReference(AiProviderProfile $provider, string $media): string
    {
        if ($media === '' || strlen($media) > 48 * 1024 * 1024
            || (! str_starts_with($media, 'data:') && (preg_match('~^https?://~i', $media) !== 1 || filter_var($media, FILTER_VALIDATE_URL) === false))) {
            throw new AiProxyException('The reference upload is invalid.', 422);
        }
        $taskId = (string) Str::uuid();
        $result = $this->runwareTasks($provider, [['taskType' => 'mediaStorage', 'taskUUID' => $taskId, 'operation' => 'upload', 'media' => $media]], 120);
        $item = collect($result['data'])->first(static fn (array $item): bool => ($item['taskUUID'] ?? $taskId) === $taskId);
        if ($result['errors'] !== [] || ! is_string($item['mediaUUID'] ?? null) || ! Str::isUuid($item['mediaUUID'])) {
            throw new AiProxyException('The reference upload could not be confirmed.', 502);
        }

        return $item['mediaUUID'];
    }

    /** @return array<string, mixed> */
    public function complete(?AiProviderProfile $provider, array $payload): array
    {
        $isCancelled = $this->cancellation($payload);
        $connection = $this->connection($provider);
        if ($connection['protocol'] === 'fal') {
            $request = FalProtocol::chatRequest($payload);
            $response = $this->send('POST', $connection['base_url'].'/'.FalProtocol::ROUTER, $connection, $request, isCancelled: $isCancelled);
            $data = $response->json();
            if (! is_array($data)) {
                throw new AiProxyException('The fal chat provider returned an invalid response.', 502);
            }

            return FalProtocol::chatResponse($data, $request['model']);
        }
        if ($connection['protocol'] === 'anthropic') {
            $request = AnthropicProtocol::request([...$payload, 'stream' => false]);
            $response = $this->send('POST', $connection['base_url'].'/messages', $connection, $request, 120, isCancelled: $isCancelled);
            $data = $response->json();
            if (! is_array($data)) {
                throw new AiProxyException('The AI provider returned an invalid response.', 502);
            }

            return AnthropicProtocol::response($data);
        }

        $request = $this->openAiPayload([...$payload, 'stream' => false]);
        $response = $this->send('POST', $connection['base_url'].'/chat/completions', $connection, $request, 120, isCancelled: $isCancelled);
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
        $isCancelled = $this->cancellation($payload);
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
            isCancelled: $isCancelled,
        );
        $body = $response->toPsrResponse()->getBody();
        if ($isCancelled !== null) {
            $source = $body;
            $body = FnStream::decorate($source, [
                'read' => function (int $length) use ($source, $isCancelled): string {
                    $this->assertNotCancelled($isCancelled);

                    return $source->read($length);
                },
                'eof' => function () use ($source, $isCancelled): bool {
                    $this->assertNotCancelled($isCancelled);

                    return $source->eof();
                },
            ]);
        }

        try {
            $events = $connection['protocol'] === 'anthropic'
                ? ProviderSseStream::anthropic($body)
                : ProviderSseStream::openAi($body);
            foreach ($events as $event) {
                $this->assertNotCancelled($isCancelled);
                yield $event;
            }
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

    public function submitImage(AiProviderProfile $provider, array $payload, string $path): array
    {
        $connection = $this->connection($provider);
        if ($connection['protocol'] !== 'kinovi') {
            throw new AiProxyException('Asynchronous image generation is not supported by this provider.', 422);
        }
        $request = KinoviProtocol::imageTask($payload);
        $submit = $this->send('POST', $connection['base_url'].'/jobs/createTask', $connection, $request, 30, image: true);
        $taskId = $submit->json('taskId');
        if (! is_string($taskId) || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $taskId) !== 1) {
            throw new AiProxyException('The Kinovi image provider did not return a valid job reference.', 502);
        }

        return ['id' => $taskId, 'status' => 'queued'];
    }

    public function imageStatus(AiProviderProfile $provider, string $taskId, string $path): array
    {
        $connection = $this->connection($provider);
        if ($connection['protocol'] !== 'kinovi' || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $taskId) !== 1) {
            throw new AiProxyException('The image status configuration is invalid.', 502);
        }
        $data = $this->send('GET', $connection['base_url'].'/jobs/recordInfo', $connection, timeout: 20, query: ['taskId' => $taskId], image: true)->json();

        return $this->kinoviTaskState($data, 'result_urls');
    }

    /**
     * Stage an owned reference without submitting a generation task.
     * The caller supplies a readable stream at its start and retains ownership.
     *
     * @param  resource  $stream
     */
    public function uploadKinoviReference(AiProviderProfile $provider, mixed $stream, string $fileName, string $mime, int $sizeBytes): string
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream' || $sizeBytes < 1
            || trim($fileName) === '' || strlen($fileName) > 255
            || str_contains($fileName, '/') || str_contains($fileName, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $fileName)
            || preg_match('/^(?:image|audio|video)\/[a-z0-9][a-z0-9.+-]*$/iD', $mime) !== 1) {
            throw new AiProxyException('The Kinovi reference upload is invalid.', 422);
        }
        $body = new Stream($stream, ['size' => $sizeBytes]);
        try {
            if (! $body->isReadable()) {
                throw new AiProxyException('The Kinovi reference upload is invalid.', 422);
            }
            $connection = $this->connection($provider);
            if ($connection['protocol'] !== 'kinovi') {
                throw new AiProxyException('Reference staging is not supported by this provider.', 422);
            }
            $requestedAt = microtime(true);
            $upload = $this->send('POST', $connection['base_url'].'/uploads', $connection, [
                'fileName' => $fileName, 'contentType' => $mime,
            ], timeout: 30)->json();
            if (! is_array($upload) || ($upload['method'] ?? null) !== 'PUT'
                || ! is_string($upload['path'] ?? null) || trim($upload['path']) === ''
                || strlen($upload['path']) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $upload['path'])
                || ! is_string($upload['contentType'] ?? null) || strcasecmp($upload['contentType'], $mime) !== 0) {
                throw new AiProxyException('The Kinovi reference upload metadata is invalid.', 502);
            }
            $options = $this->kinoviReferenceOptions($upload['uploadUrl'] ?? null);
            $this->kinoviReferenceOptions($upload['url'] ?? null);
            $deadline = $this->kinoviUploadDeadline($upload, $requestedAt);
            $timeout = (int) min(120, floor($deadline - microtime(true)));
            if ($timeout < 1) {
                throw new AiProxyException('The Kinovi reference upload has expired.', 502);
            }
            $this->putKinoviReference($upload['uploadUrl'], $body, $upload['contentType'], $sizeBytes, $options, $timeout);

            // Never follow provider-supplied confirmUrl: credentials stay on the configured API.
            $confirmed = $this->send('GET', $connection['base_url'].'/uploads', $connection,
                timeout: 20, query: ['path' => $upload['path']])->json();
            if (! is_array($confirmed) || ($confirmed['path'] ?? null) !== $upload['path']
                || ($confirmed['url'] ?? null) !== $upload['url']
                || (($confirmed['size'] ?? null) !== $sizeBytes && ($confirmed['size'] ?? null) !== (float) $sizeBytes)
                || ! is_string($confirmed['contentType'] ?? null) || strcasecmp($confirmed['contentType'], $mime) !== 0
                || ! array_key_exists('expiresAt', $confirmed) || ! $this->kinoviAssetExpiryIsUsable($confirmed['expiresAt'])) {
                throw new AiProxyException('The Kinovi reference upload could not be confirmed.', 502);
            }

            return $confirmed['url'];
        } catch (AiProxyException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AiProxyException('The Kinovi reference upload failed.', 502);
        } finally {
            // Guzzle streams close their resource on destruction unless it is detached.
            $body->detach();
        }
    }

    /** @return array<string, mixed> */
    private function kinoviReferenceOptions(mixed $url): array
    {
        if (! is_string($url) || ! GeneratedImageStore::validResultUrl($url)) {
            throw new AiProxyException('The Kinovi reference destination is invalid.', 502);
        }
        $parts = parse_url($url);
        if (isset($parts['user']) || isset($parts['pass']) || array_key_exists('fragment', $parts)
            || ($parts['path'] ?? '') === '') {
            throw new AiProxyException('The Kinovi reference destination is invalid.', 502);
        }
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $this->endpoint->requestOptions($origin);
    }

    private function kinoviUploadDeadline(array $upload, float $requestedAt): float
    {
        foreach (['expiresIn', 'assetTtlSeconds'] as $field) {
            $seconds = $upload[$field] ?? null;
            if ((! is_int($seconds) && ! is_float($seconds)) || ! is_finite((float) $seconds) || $seconds <= 0) {
                throw new AiProxyException('The Kinovi reference upload metadata is invalid.', 502);
            }
        }
        $deadline = $requestedAt + $upload['expiresIn'];
        parse_str((string) parse_url($upload['uploadUrl'], PHP_URL_QUERY), $query);
        if (array_key_exists('X-Amz-Date', $query) || array_key_exists('X-Amz-Expires', $query)) {
            $date = $query['X-Amz-Date'] ?? null;
            $seconds = $query['X-Amz-Expires'] ?? null;
            if (! is_string($date) || ! is_string($seconds) || preg_match('/^[0-9]{1,10}$/D', $seconds) !== 1
                || (int) $seconds < 1) {
                throw new AiProxyException('The Kinovi reference upload metadata is invalid.', 502);
            }
            $signedAt = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $date, new DateTimeZone('UTC'));
            if ($signedAt === false || $signedAt->format('Ymd\THis\Z') !== $date) {
                throw new AiProxyException('The Kinovi reference upload metadata is invalid.', 502);
            }
            $deadline = min($deadline, $signedAt->getTimestamp() + (int) $seconds);
        }

        return $deadline;
    }

    private function kinoviAssetExpiryIsUsable(mixed $expiry): bool
    {
        if ($expiry === null) {
            return true;
        }
        if (! is_string($expiry)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $expiry) !== 1) {
            return false;
        }
        try {
            $date = new DateTimeImmutable($expiry);
            $errors = DateTimeImmutable::getLastErrors();

            return $errors === false && $date->getTimestamp() > time();
        } catch (Throwable) {
            return false;
        }
    }

    private function putKinoviReference(string $url, StreamInterface $body, string $mime, int $sizeBytes, array $options, int $timeout): void
    {
        $handler = new CurlHandler;
        $response = Http::withHeaders(['Content-Length' => (string) $sizeBytes])
            ->withBody($body, $mime)->timeout($timeout)->connectTimeout(10)
            ->withOptions([...$options, 'stream' => false, 'cookies' => false, 'auth' => null])
            ->setHandler(static function (RequestInterface $request, array $options) use ($handler, $sizeBytes): PromiseInterface {
                // Guzzle otherwise copies sub-1MB bodies into CURLOPT_POSTFIELDS. Let cURL
                // supply Content-Length from INFILESIZE while reading the stream directly.
                $options['curl'][CURLOPT_INFILESIZE] = $sizeBytes;

                return $handler($request->withoutHeader('Content-Length'), $options);
            })->send('PUT', $url);
        if (! $response->successful()) {
            throw new AiProxyException('The Kinovi reference upload failed.', 502);
        }
    }

    public function submitVideo(AiProviderProfile $provider, array $payload, string $path): array
    {
        $connection = $this->connection($provider);
        if ($connection['protocol'] === 'fal') {
            $request = FalProtocol::videoRequest($payload);
            $response = $this->send('POST', $this->falBase($connection).'/'.$payload['model'], $connection, $request, 90);
            $data = $response->json();
            if (! is_array($data) || ! is_string($data['request_id'] ?? null)
                || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $data['request_id']) !== 1) {
                throw new AiProxyException('The fal video provider did not return a valid job reference.', 502);
            }

            return ['id' => $data['request_id'], 'status' => 'queued'];
        }
        if ($connection['protocol'] === 'kinovi') {
            $request = KinoviProtocol::videoTask($payload);
            $submit = $this->send('POST', $connection['base_url'].'/jobs/createTask', $connection, $request, 30);
            $taskId = $submit->json('taskId');
            if (! is_string($taskId) || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $taskId) !== 1) {
                throw new AiProxyException('The Kinovi video provider did not return a valid job reference.', 502);
            }

            return ['id' => $taskId, 'status' => 'queued'];
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
            if (! in_array($path, [FalProtocol::VIDEO_REQUEST_PATH, FalProtocol::AVATAR_REQUEST_PATH], true)) {
                throw new AiProxyException('The fal video status configuration is invalid.', 502);
            }
            $url = $this->falBase($connection).'/'.MediaModelConfig::path($path, $taskId);
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
        if ($connection['protocol'] === 'kinovi') {
            $data = $this->send('GET', $connection['base_url'].'/jobs/recordInfo', $connection, timeout: 20, query: ['taskId' => $taskId])->json();

            return $this->kinoviTaskState($data, 'video_url');
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
        if ($connection['protocol'] === 'kinovi') {
            $request = KinoviProtocol::audioTask($payload);
            $submit = $this->send('POST', $connection['base_url'].'/jobs/createTask', $connection, $request, 30);
            $taskId = $submit->json('taskId');
            if (! is_string($taskId) || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $taskId) !== 1) {
                throw new AiProxyException('The Kinovi audio provider did not return a valid job reference.', 502);
            }

            return ['id' => $taskId, 'status' => 'queued'];
        }
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
        if (preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $taskId) !== 1) {
            throw new AiProxyException('The audio status configuration is invalid.', 502);
        }
        if ($connection['protocol'] === 'kinovi') {
            $data = $this->send('GET', $connection['base_url'].'/jobs/recordInfo', $connection, timeout: 20, query: ['taskId' => $taskId])->json();

            return $this->kinoviTaskState($data, 'result_urls');
        }
        if ($connection['protocol'] !== 'fal'
            || ! in_array($path, [FalProtocol::AUDIO_SPEECH_REQUEST_PATH, FalProtocol::AUDIO_MUSIC_REQUEST_PATH], true)) {
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

        return ['status' => 'completed', 'result_urls' => [$audioUrl]];
    }

    /**
     * Normalise a Kinovi recordInfo response into the shared async media state shape.
     *
     * @param  'result_urls'|'video_url'  $key
     * @return array<string, mixed>
     */
    private function kinoviTaskState(mixed $data, string $key): array
    {
        $status = is_array($data) ? ($data['status'] ?? null) : null;
        if ($status === 'fail') {
            return ['status' => 'failed'];
        }
        if ($status !== 'success') {
            return ['status' => 'processing'];
        }
        $urls = KinoviProtocol::outputUrls(is_array($data) ? $data : []);

        return $key === 'result_urls'
            ? ['status' => 'completed', 'result_urls' => $urls]
            : ['status' => 'completed', $key => $urls[0]];
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
                    'input_modalities' => match ($category) {
                        'avatar' => ['text', 'image', 'audio'],
                        'video' => ['text', 'image'],
                        default => ['text'],
                    },
                    'output_modalities' => [$chat ? 'text' : ($category === 'avatar' ? 'video' : $category)],
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
        if (! in_array($protocol, ['openai', 'anthropic', 'fal', 'kinovi', 'runware'], true)) {
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
     * The fal endpoint base. Production always uses queue.fal.run; the double-gated local dev
     * affordance (APP_ENV=local + media.allow_local_providers) lets a loopback provider base_url
     * point video traffic at a local mock instead. Production never satisfies both gates.
     *
     * @param  array{base_url: string}  $connection
     */
    private function falBase(array $connection): string
    {
        $host = (string) parse_url($connection['base_url'], PHP_URL_HOST);
        if ($this->endpoint->allowsLoopback($host)) {
            return preg_replace('#/v1$#', '', rtrim($connection['base_url'], '/'));
        }

        return 'https://queue.fal.run';
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
        bool $catalogRetry = false,
        array $headers = [],
        ?callable $isCancelled = null,
        bool $errorCodes = false,
    ): Response {
        $this->assertNotCancelled($isCancelled);
        if ($connection['protocol'] === 'fal') {
            $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
            if ($this->endpoint->allowsLoopback($host)) {
                // Local dev affordance only: a loopback fal mock stands in for queue.fal.run.
                $port = parse_url($url, PHP_URL_PORT);
                $connection['options'] = $this->endpoint->requestOptions(parse_url($url, PHP_URL_SCHEME).'://'.$host.($port ? ':'.$port : ''));
            } else {
                if (parse_url($url, PHP_URL_SCHEME) !== 'https' || (parse_url($url, PHP_URL_PORT) ?: 443) !== 443
                    || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
                    || parse_url($url, PHP_URL_FRAGMENT) !== null
                    || ! in_array($host, ['fal.run', 'queue.fal.run', 'api.fal.ai', 'rest.fal.ai', 'wma.fal.run'], true)) {
                    throw new AiProxyException('The fal provider destination is invalid.', 503);
                }
                $connection['options'] = $this->endpoint->requestOptions('https://'.$host);
            }
        }
        if ($stream && $connection['saved'] && $isCancelled === null) {
            [$host, $port, $ip] = $this->pinnedStreamTarget($connection);
            $connection['stream_target'] = [$host, $port, $ip];
        }
        $request = $this->request($connection, $timeout, $stream, $isCancelled)->withHeaders($headers);
        if ($method === 'POST') {
            // Guzzle's cURL factory otherwise retries failed rewinds implicitly. Paid POSTs
            // must not replay, including after a stale keep-alive connection fails.
            $request->withOptions([
                '_curl_retries' => 2,
                'curl' => array_replace($connection['options']['curl'] ?? [], [CURLOPT_FRESH_CONNECT => true]),
            ]);
        }
        $attempts = $catalogRetry && $method === 'GET' ? 3 : 1;
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $this->assertNotCancelled($isCancelled);
                $response = $request->send($method, $url, array_filter([
                    'json' => $json === [] ? new \stdClass : $json,
                    'query' => $query !== [] ? $query : null,
                ], static fn (mixed $value): bool => $value !== null));
            } catch (ConnectionException) {
                if ($attempt + 1 < $attempts) {
                    usleep(250000 * (2 ** $attempt));

                    continue;
                }
                throw new AiProxyException($image ? 'The AI image provider is unavailable.' : 'The AI provider is unavailable.', 503);
            } catch (AiProxyException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new AiProxyException($image ? 'The AI image provider is unavailable.' : 'The AI provider is unavailable.', 503);
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt + 1 < $attempts) {
                $retryAfter = $response->header('Retry-After');
                $delay = ctype_digit((string) $retryAfter) ? (int) $retryAfter : max(0, (strtotime((string) $retryAfter) ?: time()) - time());
                if ($delay > 2) {
                    break; // Respect long Retry-After by returning; never retry earlier than requested.
                }
                usleep((int) (max($delay, 0.25 * (2 ** $attempt)) * 1000000));

                continue;
            }
            break;
        }

        if (! $response->successful()) {
            $providerCode = $errorCodes ? self::providerErrorCode($response) : null;
            $response->toPsrResponse()->getBody()->close();
            if ($image && in_array($response->status(), [404, 405, 501], true)) {
                throw new AiProviderRequestRejected('Image generation is not supported by the AI provider.', 502, $response->status());
            }
            $unavailable = $response->status() === 429 || $response->serverError();
            $message = $unavailable
                ? ($image ? 'The AI image provider is unavailable.' : 'The AI provider is unavailable.')
                : ($image ? 'The AI image provider rejected the request.' : 'The AI provider rejected the request.');
            if ($response->clientError() && ! in_array($response->status(), [408, 409, 429], true)) {
                throw new AiProviderRequestRejected($message, 502, $response->status(), $providerCode);
            }
            throw new AiProxyException($message, $unavailable ? 503 : 502);
        }

        return $response;
    }

    /** The provider's own error code from a small JSON error body (`errors[0].code` or `error.code`), sanitized. */
    private static function providerErrorCode(Response $response): ?string
    {
        try {
            $body = strlen($response->body()) <= 65536 ? $response->json() : null;
        } catch (Throwable) {
            return null;
        }
        $error = is_array($body) ? ($body['errors'][0] ?? $body['error'] ?? null) : null;
        $code = is_array($error) ? ($error['code'] ?? null) : null;

        return is_string($code) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $code) === 1 ? $code : null;
    }

    /**
     * @param  array{protocol: string, base_url: string, key: string, version: string, options: array<string, mixed>, saved: bool, stream_target?: array{string, int, string}}  $connection
     */
    private function request(array $connection, int $timeout, bool $stream, ?callable $isCancelled = null): PendingRequest
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

        if ($isCancelled !== null) {
            $request->setHandler(static function (RequestInterface $psrRequest, array $options) use ($isCancelled): PromiseInterface {
                return new FulfilledPromise(CancellableProviderStream::send($psrRequest, $options, $isCancelled));
            });
        } elseif ($stream && $connection['saved']) {
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

    /** Extract the one internal callback before protocol validation/JSON serialization. */
    private function cancellation(array &$payload): ?callable
    {
        $callback = $payload['_is_cancelled'] ?? null;
        unset($payload['_is_cancelled']);
        // JSON cannot carry object callables. Never execute a submitted PHP function/class name.
        if ($callback !== null && (! is_callable($callback)
            || (! is_object($callback) && ! (is_array($callback) && is_object($callback[0] ?? null))))) {
            throw new AiProxyException('The internal cancellation option is invalid.', 422);
        }
        $this->assertNotCancelled($callback);
        if ($callback !== null && ! extension_loaded('curl')) {
            throw new AiProxyException('Interruptible provider transport is unavailable.', 503);
        }

        return $callback;
    }

    private function assertNotCancelled(?callable $isCancelled): void
    {
        if ($isCancelled !== null && $isCancelled()) {
            throw new AiProxyException('The AI request was stopped.', 499);
        }
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
