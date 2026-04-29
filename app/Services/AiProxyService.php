<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiProxyService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.ai_proxy.url', env('ENOWX_API_URL', 'https://api.ultrai.id')), '/');
        $this->apiKey = config('services.ai_proxy.key', env('ENOWX_API_KEY', ''));
    }

    /**
     * Get available AI models (filtered to chat category)
     */
    public function getModels(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(10)->get($this->baseUrl . '/v1/models');

            if ($response->successful()) {
                $data = $response->json();
                return collect($data['data'] ?? [])
                    ->filter(fn($m) => ($m['category'] ?? '') === 'chat')
                    ->filter(fn($m) => !str_contains(strtolower($m['id'] ?? ''), 'enowx'))
                    ->map(fn($m) => $this->scrubModel($m))
                    ->values()
                    ->toArray();
            }

            Log::warning('AI Proxy models request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error('AI Proxy connection failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get all models (unfiltered)
     */
    public function getAllModels(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(10)->get($this->baseUrl . '/v1/models');

            if ($response->successful()) {
                return $response->json()['data'] ?? [];
            }

            return [];
        } catch (\Exception $e) {
            Log::error('AI Proxy connection failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Send chat completion (non-streaming)
     */
    public function chatCompletion(array $messages, string $model = 'auto', array $options = []): ?array
    {
        try {
            $payload = array_merge([
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
            ], $options);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post($this->baseUrl . '/v1/chat/completions', $payload);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('AI Proxy chat completion failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('AI Proxy chat error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Send chat completion with streaming (SSE)
     * Returns a StreamedResponse for direct use in controllers
     */
    public function chatCompletionStream(array $messages, string $model = 'auto', ?\Closure $onChunk = null): StreamedResponse
    {
        return new StreamedResponse(function () use ($messages, $model, $onChunk) {
            $ch = curl_init();

            $postData = json_encode([
                'model' => $model,
                'messages' => $messages,
                'stream' => true,
            ]);

            $fullResponse = '';

            curl_setopt_array($ch, [
                CURLOPT_URL => $this->baseUrl . '/v1/chat/completions',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                ],
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$fullResponse, $onChunk) {
                    echo $data;
                    if (ob_get_level()) ob_flush();
                    flush();

                    // Parse SSE data to collect full response
                    $lines = explode("\n", $data);
                    foreach ($lines as $line) {
                        if (str_starts_with($line, 'data: ') && $line !== 'data: [DONE]') {
                            $json = json_decode(substr($line, 6), true);
                            $content = $json['choices'][0]['delta']['content'] ?? null;
                            if ($content) {
                                $fullResponse .= $content;
                            }
                        }
                    }

                    return strlen($data);
                },
            ]);

            curl_exec($ch);

            $error = curl_error($ch);
            if ($error) {
                Log::error('AI Proxy stream error', ['error' => $error]);
            }

            curl_close($ch);

            // Callback with full response for saving to DB etc.
            if ($onChunk && $fullResponse) {
                $onChunk($fullResponse);
            }
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
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(5)->get($this->baseUrl . '/v1/models');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get proxy status info
     */
    public function getStatus(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(5)->get($this->baseUrl . '/v1/models');

            if ($response->successful()) {
                $models = $response->json()['data'] ?? [];
                return [
                    'online' => true,
                    'total_models' => count($models),
                    'chat_models' => collect($models)->where('category', 'chat')->count(),
                ];
            }

            return ['online' => false];
        } catch (\Exception $e) {
            return ['online' => false];
        }
    }

    /**
     * Scrub proxy brand references from model data
     */
    private function scrubModel(array $model): array
    {
        $search = ['enowxai', 'enowx labs', 'EnowXAI', 'EnowX Labs', 'EnowX', 'enowx', 'ENOWX'];
        $replace = ['UltrAI', 'UltrAI', 'UltrAI', 'UltrAI', 'UltrAI', 'UltrAI', 'UltrAI'];

        array_walk_recursive($model, function (&$value) use ($search, $replace) {
            if (is_string($value)) {
                $value = str_ireplace($search, 'UltrAI', $value);
            }
        });

        return $model;
    }
}
