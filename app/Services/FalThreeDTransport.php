<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Models\AiProviderProfile;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Narrow queue transport: never retries POST and never follows provider-returned queue URLs. */
final class FalThreeDTransport
{
    public function __construct(private readonly AiProviderEndpoint $endpoint) {}

    public function submit(AiProviderProfile $provider, string $model, string $imageDataUri, array $settings): string
    {
        $payload = ThreeDProtocol::request($model, $imageDataUri, $settings);
        $data = $this->send($provider, 'POST', ThreeDProtocol::MODEL, $payload);
        $id = $data['request_id'] ?? null;
        if (! is_string($id) || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $id) !== 1) {
            throw new AiProxyException('The 3D provider did not return a valid request reference.', 502);
        }

        return $id;
    }

    public function status(AiProviderProfile $provider, string $requestId): array
    {
        if (preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $requestId) !== 1) {
            throw new AiProxyException('The 3D request reference is invalid.', 502);
        }
        $path = MediaModelConfig::path(ThreeDProtocol::REQUEST_PATH, $requestId);
        $data = $this->send($provider, 'GET', $path.'/status');
        if (! empty($data['error']) || ! empty($data['error_type'])) {
            return ['status' => 'failed'];
        }
        if (in_array($data['status'] ?? null, ['IN_QUEUE', 'IN_PROGRESS'], true)) {
            return ['status' => 'processing'];
        }
        if (($data['status'] ?? null) !== 'COMPLETED') {
            throw new AiProxyException('The 3D provider returned an invalid status.', 502);
        }
        $result = $this->send($provider, 'GET', $path);
        if (! empty($result['error']) || ! empty($result['error_type'])) {
            return ['status' => 'failed'];
        }
        $url = $result['model_glb']['url'] ?? null;
        if (! is_string($url) || ! GeneratedModel3dStore::validResultUrl($url)) {
            throw new AiProxyException('The 3D provider returned an invalid model result.', 502);
        }

        return ['status' => 'completed', 'model_url' => $url];
    }

    private function send(AiProviderProfile $provider, string $method, string $path, ?array $payload = null): array
    {
        try {
            if ($provider->protocol !== 'fal' || ! $provider->is_enabled || trim((string) $provider->api_key) === '') {
                throw new AiProxyException('The 3D provider is unavailable.', 503);
            }
            $base = $this->endpoint->normalize((string) $provider->base_url, 'fal');
            // Preserve the established double-gated local provider affordance; production is fixed.
            $origin = $this->endpoint->allowsLoopback((string) parse_url($base, PHP_URL_HOST))
                ? preg_replace('#/v1$#', '', rtrim($base, '/')) : 'https://queue.fal.run';
            $options = $this->endpoint->requestOptions($origin);
            $response = Http::withHeaders(['Authorization' => 'Key '.$provider->api_key])
                ->acceptJson()->asJson()->timeout($method === 'POST' ? 90 : 20)->connectTimeout(10)
                ->withOptions([...$options, 'cookies' => false, 'allow_redirects' => false])
                ->send($method, $origin.'/'.MediaModelConfig::path($path), $payload === null ? [] : ['json' => $payload]);
        } catch (AiProxyException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Submission outcome can be ambiguous: caller releases locally but never repeats this POST.
            throw new AiProxyException('The 3D provider connection could not be completed.', 503);
        }
        if (! $response->successful()) {
            throw new AiProxyException('The 3D provider could not complete this request.',
                $response->serverError() || $response->status() === 429 ? 503 : 502);
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new AiProxyException('The 3D provider returned an invalid response.', 502);
        }

        return $data;
    }
}
