<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiProxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExternalApiController extends Controller
{
    private AiProxyService $aiProxy;
    private array $scrubSearch;
    private string $scrubReplace = 'UltrAI';

    // System prompt to override AI identity
    private string $systemPrompt = 'You are UltrAI, an AI assistant powered by UltrAI Platform (ultrai.id). Never mention or reference any other AI platform, proxy, or service provider. If asked about your identity, system, or infrastructure, always say you are UltrAI. Never mention enowx, enowxai, enowx labs, or any similar names.';

    public function __construct(AiProxyService $aiProxy)
    {
        $this->aiProxy = $aiProxy;
        $this->scrubSearch = ['enowxai', 'enowx labs', 'EnowXAI', 'EnowX Labs', 'EnowX', 'enowx', 'ENOWX', 'enowx labs chat ui', 'EnowX Labs Chat UI'];
    }

    /**
     * GET /v1/models
     */
    public function models(Request $request)
    {
        $user = $request->get('_api_user');
        $apiKey = $request->get('_api_key');
        $allowedTiers = $user->getAllowedTiers();
        $models = $this->aiProxy->getModels($allowedTiers);

        if ($apiKey->allowed_models) {
            $models = array_values(array_filter($models, fn($m) => in_array($m['id'], $apiKey->allowed_models)));
        }

        return response()->json([
            'object' => 'list',
            'data' => $models,
        ]);
    }

    /**
     * POST /v1/chat/completions
     */
    public function chatCompletions(Request $request)
    {
        $user = $request->get('_api_user');
        $apiKey = $request->get('_api_key');

        $validated = $request->validate([
            'model' => 'required|string',
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|string',
            'messages.*.content' => 'required|string',
            'stream' => 'nullable|boolean',
        ]);

        // Check model allowed by API key
        if ($apiKey->allowed_models && !in_array($validated['model'], $apiKey->allowed_models)) {
            return response()->json([
                'error' => ['message' => 'Model not allowed for this API key', 'type' => 'permission_error']
            ], 403);
        }

        // Check model allowed by user permissions (tier check)
        $allowedTiers = $user->getAllowedTiers();
        $allowedModels = $this->aiProxy->getModels($allowedTiers);
        $allowedModelIds = array_column($allowedModels, 'id');
        if (!in_array($validated['model'], $allowedModelIds)) {
            return response()->json([
                'error' => ['message' => 'Model not available for your account', 'type' => 'permission_error']
            ], 403);
        }

        $proxyUrl = rtrim(config('services.ai_proxy.url', env('AI_PROXY_URL', env('ENOWX_API_URL'))), '/');
        $proxyKey = config('services.ai_proxy.key', env('AI_PROXY_KEY', env('ENOWX_API_KEY')));
        $isStream = $validated['stream'] ?? false;

        // Inject system prompt to override AI identity
        $messages = $this->injectSystemPrompt($validated['messages']);

        if ($isStream) {
            return new StreamedResponse(function () use ($validated, $messages, $proxyUrl, $proxyKey) {
                $ch = curl_init();
                $scrubSearch = $this->scrubSearch;
                $scrubReplace = $this->scrubReplace;

                curl_setopt_array($ch, [
                    CURLOPT_URL => $proxyUrl . '/v1/chat/completions',
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode([
                        'model' => $validated['model'],
                        'messages' => $messages,
                        'stream' => true,
                    ]),
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $proxyKey,
                        'Content-Type: application/json',
                        'Accept: text/event-stream',
                    ],
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_TIMEOUT => 120,
                    CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($scrubSearch, $scrubReplace) {
                        $clean = str_ireplace($scrubSearch, $scrubReplace, $data);
                        echo $clean;
                        if (ob_get_level()) ob_flush();
                        flush();
                        return strlen($data);
                    },
                ]);
                curl_exec($ch);
                curl_close($ch);
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        // Non-streaming
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $proxyKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post($proxyUrl . '/v1/chat/completions', [
            'model' => $validated['model'],
            'messages' => $messages,
            'stream' => false,
        ]);

        $body = str_ireplace($this->scrubSearch, $this->scrubReplace, $response->body());

        return response($body, $response->status())
            ->header('Content-Type', 'application/json');
    }

    /**
     * Inject system prompt at the beginning of messages
     */
    private function injectSystemPrompt(array $messages): array
    {
        // Check if first message is already a system prompt
        if (!empty($messages) && $messages[0]['role'] === 'system') {
            // Prepend our identity override to existing system prompt
            $messages[0]['content'] = $this->systemPrompt . "\n\n" . $messages[0]['content'];
        } else {
            // Add system prompt at the beginning
            array_unshift($messages, [
                'role' => 'system',
                'content' => $this->systemPrompt,
            ]);
        }

        return $messages;
    }
}
