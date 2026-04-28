<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            $conversations = $request->user()
                ->chatConversations()
                ->withCount('messages')
                ->latest()
                ->get();

            return response()->json($conversations);
        }

        return view('app');
    }

    public function show(ChatConversation $conversation)
    {
        $this->authorize('view', $conversation);

        return response()->json([
            'conversation' => $conversation,
            'messages' => $conversation->messages()->orderBy('created_at')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $conversation = $request->user()->chatConversations()->create([
            'title' => $request->input('title', 'New Chat'),
            'model' => $request->input('model', 'auto'),
        ]);

        return response()->json($conversation, 201);
    }

    public function send(Request $request, ChatConversation $conversation)
    {
        $this->authorize('view', $conversation);

        $request->validate([
            'message' => 'required|string|max:10000',
            'model' => 'sometimes|string',
        ]);

        // Save user message
        $userMessage = $conversation->messages()->create([
            'user_id' => $request->user()->id,
            'role' => 'user',
            'content' => $request->message,
        ]);

        // Update conversation title if first message
        if ($conversation->messages()->count() === 1) {
            $conversation->update([
                'title' => str()->limit($request->message, 50),
            ]);
        }

        // Get conversation history
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($msg) => [
                'role' => $msg->role,
                'content' => $msg->content,
            ])
            ->toArray();

        // Call AI Proxy
        $model = $request->input('model', $conversation->model ?? 'auto');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.ai_proxy.key'),
                'Content-Type' => 'application/json',
            ])
            ->timeout(120)
            ->post(config('services.ai_proxy.url') . '/v1/chat/completions', [
                'model' => $model,
                'messages' => $history,
                'stream' => false,
            ]);

            $data = $response->json();
            $aiContent = $data['choices'][0]['message']['content'] ?? 'Maaf, terjadi kesalahan.';
            $tokensUsed = $data['usage']['total_tokens'] ?? 0;

            // Save AI response
            $aiMessage = $conversation->messages()->create([
                'user_id' => $request->user()->id,
                'role' => 'assistant',
                'content' => $aiContent,
                'model' => $model,
                'tokens_used' => $tokensUsed,
            ]);

            return response()->json([
                'user_message' => $userMessage,
                'ai_message' => $aiMessage,
            ]);
        } catch (\Exception $e) {
            $errorMessage = $conversation->messages()->create([
                'user_id' => $request->user()->id,
                'role' => 'assistant',
                'content' => 'Maaf, terjadi kesalahan saat menghubungi AI. Silakan coba lagi.',
                'model' => $model,
            ]);

            return response()->json([
                'user_message' => $userMessage,
                'ai_message' => $errorMessage,
                'error' => true,
            ], 500);
        }
    }

    public function stream(Request $request, ChatConversation $conversation)
    {
        $this->authorize('view', $conversation);

        $request->validate([
            'message' => 'required|string|max:10000',
            'model' => 'sometimes|string',
        ]);

        // Save user message
        $conversation->messages()->create([
            'user_id' => $request->user()->id,
            'role' => 'user',
            'content' => $request->message,
        ]);

        // Update title if first message
        if ($conversation->messages()->count() === 1) {
            $conversation->update([
                'title' => str()->limit($request->message, 50),
            ]);
        }

        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($msg) => [
                'role' => $msg->role,
                'content' => $msg->content,
            ])
            ->toArray();

        $model = $request->input('model', $conversation->model ?? 'auto');

        return response()->stream(function () use ($history, $model, $conversation, $request) {
            $fullContent = '';

            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.ai_proxy.key'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'text/event-stream',
                ])
                ->timeout(120)
                ->withOptions(['stream' => true])
                ->post(config('services.ai_proxy.url') . '/v1/chat/completions', [
                    'model' => $model,
                    'messages' => $history,
                    'stream' => true,
                ]);

                $body = $response->getBody();

                while (!$body->eof()) {
                    $line = '';
                    while (!$body->eof()) {
                        $char = $body->read(1);
                        if ($char === "\n") break;
                        $line .= $char;
                    }

                    $line = trim($line);
                    if (empty($line) || !str_starts_with($line, 'data: ')) continue;

                    $data = substr($line, 6);
                    if ($data === '[DONE]') {
                        echo "data: [DONE]\n\n";
                        ob_flush();
                        flush();
                        break;
                    }

                    $json = json_decode($data, true);
                    if (isset($json['choices'][0]['delta']['content'])) {
                        $chunk = $json['choices'][0]['delta']['content'];
                        $fullContent .= $chunk;
                        echo "data: " . json_encode(['content' => $chunk]) . "\n\n";
                        ob_flush();
                        flush();
                    }
                }
            } catch (\Exception $e) {
                echo "data: " . json_encode(['error' => 'Stream error']) . "\n\n";
                ob_flush();
                flush();
            }

            // Save complete AI message
            if (!empty($fullContent)) {
                $conversation->messages()->create([
                    'user_id' => $request->user()->id,
                    'role' => 'assistant',
                    'content' => $fullContent,
                    'model' => $model,
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function destroy(ChatConversation $conversation)
    {
        $this->authorize('delete', $conversation);
        $conversation->delete();

        return response()->json(['message' => 'Conversation deleted']);
    }
}
