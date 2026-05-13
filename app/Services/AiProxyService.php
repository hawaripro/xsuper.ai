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
    public function getModels(array $allowedTiers = []): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(10)->get($this->baseUrl . '/v1/models');

            if ($response->successful()) {
                $data = $response->json();
                $tierMap = [
                    'Standard' => 'Original',
                    'MAX' => 'Authentic',
                    'Codex' => 'Codex',
                    'Wavespeed' => 'Wavespeed',
                    'YepAPI' => 'YepAPI',
                    'Canva' => 'Canva',
                ];

                if (empty($allowedTiers)) {
                    $allowedTiers = ['Standard', 'MAX'];
                }

                $allowedCategories = ['chat'];
                if (in_array('Canva', $allowedTiers)) {
                    $allowedCategories[] = 'image';
                    $allowedCategories[] = 'canva';
                }

                return collect($data['data'] ?? [])
                    ->filter(fn($m) => in_array($m['category'] ?? '', $allowedCategories))
                    ->filter(fn($m) => in_array($m['tier'] ?? '', $allowedTiers))
                    ->filter(fn($m) => !str_contains(strtolower($m['id'] ?? ''), 'enowx'))
                    ->filter(fn($m) => ($m['id'] ?? '') !== 'auto')
                    ->map(fn($m) => $this->scrubModel($m, $tierMap))
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
                    $data = \App\Http\Controllers\Api\ExternalApiController::clean($data);

                    // Forward data immediately (no validation delay)
                    echo $data;
                    if (ob_get_level()) ob_flush();
                    flush();

                    // Parse for collecting full response (non-blocking)
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
     * Get all models across ALL categories (chat, image, video, audio) with tier filtering
     * Used by the full-page chat UI
     */
    public function getAllModelsFiltered(array $allowedTiers = []): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(10)->get($this->baseUrl . '/v1/models');

            if ($response->successful()) {
                $data = $response->json();
                $tierMap = [
                    'Standard' => 'Original',
                    'MAX' => 'Authentic',
                    'Codex' => 'Codex',
                    'Wavespeed' => 'Wavespeed',
                    'YepAPI' => 'YepAPI',
                    'Canva' => 'Canva',
                ];

                if (empty($allowedTiers)) {
                    $allowedTiers = ['Standard', 'MAX'];
                }

                return collect($data['data'] ?? [])
                    ->filter(fn($m) => in_array($m['tier'] ?? '', $allowedTiers))
                    ->filter(fn($m) => !str_contains(strtolower($m['id'] ?? ''), 'enowx'))
                    ->filter(fn($m) => ($m['id'] ?? '') !== 'auto')
                    ->filter(fn($m) => !str_contains(strtolower($m['id'] ?? ''), 'default'))
                    ->map(fn($m) => $this->scrubModelFull($m, $tierMap))
                    ->values()
                    ->push(...$this->getAliasModels($allowedTiers))
                    ->toArray();
            }

            return [];
        } catch (\Exception $e) {
            Log::error('AI Proxy connection failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Scrub model data — only return safe fields, remove all sensitive info
     */
    private function scrubModel(array $model, array $tierMap = []): array
    {
        $tier = $model['tier'] ?? 'Standard';
        return [
            'id' => $model['id'] ?? 'unknown',
            'name' => $this->scrubText($model['name'] ?? $model['id'] ?? 'unknown'),
            'category' => $tierMap[$tier] ?? 'Original',
        ];
    }

    /**
     * Get alias models that map to real models
     */
    private function getAliasModels(array $allowedTiers): array
    {
        if (!in_array('Standard', $allowedTiers)) return [];

        return [
            ['id' => 'claude-opus-4-6', 'name' => 'Claude Opus 4-6', 'tier' => 'Original', 'category' => 'chat'],
            ['id' => 'claude-opus-4-7', 'name' => 'Claude Opus 4-7', 'tier' => 'Original', 'category' => 'chat'],
            ['id' => 'gpt-5-5', 'name' => 'GPT-5-5', 'tier' => 'Original', 'category' => 'chat'],
        ];
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

    /**
     * Remove proxy brand references from text
     */
    private function scrubText(string $text): string
    {
        $text = str_ireplace(
            ['enowxai', 'enowx labs', 'EnowXAI', 'EnowX Labs', 'EnowX', 'enowx', 'ENOWX', 'enowxlabs', 'EnowXLabs', 'ENOWXLABS'],
            'UltrAI',
            $text
        );
        $text = preg_replace('/\benowx\w*/i', 'UltrAI', $text);
        $text = preg_replace('/UltrAI\s+Labs/i', 'UltrAI', $text);
        $text = preg_replace('/\bLabs\b/', '', $text);
        return $text;
    }
}
