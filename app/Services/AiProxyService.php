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

                    // Fix: Filter out empty/malformed data lines that cause JSON parse errors
                    $lines = explode("\n", $data);
                    $cleanedLines = [];
                    foreach ($lines as $line) {
                        $trimmed = trim($line);
                        // Skip empty "data: " lines (no JSON payload)
                        if ($trimmed === 'data:' || $trimmed === 'data: ') {
                            continue;
                        }
                        // Validate JSON in data lines before forwarding
                        if (str_starts_with($trimmed, 'data: ') && $trimmed !== 'data: [DONE]') {
                            $jsonStr = substr($trimmed, 6);
                            $parsed = json_decode($jsonStr, true);
                            if ($parsed === null && json_last_error() !== JSON_ERROR_NONE) {
                                // Skip malformed JSON lines
                                continue;
                            }
                            $content = $parsed['choices'][0]['delta']['content'] ?? null;
                            if ($content) {
                                $fullResponse .= $content;
                            }
                        }
                        $cleanedLines[] = $line;
                    }

                    $cleanedData = implode("\n", $cleanedLines);
                    if (trim($cleanedData) !== '') {
                        echo $cleanedData;
                        if (ob_get_level()) ob_flush();
                        flush();
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
