<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiProxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    private AiProxyService $aiProxy;

    public function __construct(AiProxyService $aiProxy)
    {
        $this->aiProxy = $aiProxy;
    }

    /**
     * Get available AI models
     */
    public function models(Request $request)
    {
        $user = $request->user();
        $allowedTiers = $user->getAllowedTiers();
        $models = $this->aiProxy->getModels($allowedTiers);
        return response()->json(['models' => $models]);
    }

    /**
     * Send chat message (streaming SSE)
     */
    public function send(Request $request)
    {
        $request->validate([
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|string|in:user,assistant,system',
            'messages.*.content' => 'required|string',
            'model' => 'nullable|string',
            'conversation_id' => 'nullable|string|max:100',
        ]);

        $user = Auth::user();
        $model = $request->input('model', 'auto');
        $messages = $request->input('messages');
        $conversationId = $request->input('conversation_id');

        // Save user message to history
        $lastMsg = end($messages);
        if ($conversationId && $lastMsg) {
            DB::table('chat_history')->insert([
                'user_id' => $user->id,
                'conversation_id' => $conversationId,
                'role' => $lastMsg['role'],
                'content' => $lastMsg['content'],
                'model' => $model,
                'created_at' => now(),
            ]);
        }

        // Stream response from AI proxy via AiProxyService
        return $this->aiProxy->chatCompletionStream(
            $messages,
            $model,
            function (string $fullResponse) use ($user, $conversationId, $model) {
                // Save assistant response to history after stream completes
                if ($fullResponse && $conversationId) {
                    DB::table('chat_history')->insert([
                        'user_id' => $user->id,
                        'conversation_id' => $conversationId,
                        'role' => 'assistant',
                        'content' => $fullResponse,
                        'model' => $model,
                        'created_at' => now(),
                    ]);
                }
            }
        );
    }

    /**
     * Get chat history (list of conversations)
     */
    public function history(Request $request)
    {
        $user = Auth::user();

        $conversations = DB::table('chat_history')
            ->where('user_id', $user->id)
            ->select(
                'conversation_id',
                DB::raw('MIN(created_at) as started_at'),
                DB::raw('MAX(created_at) as last_message'),
                DB::raw('COUNT(*) as message_count'),
                DB::raw("(SELECT content FROM chat_history ch2 WHERE ch2.conversation_id = chat_history.conversation_id AND ch2.role = 'user' ORDER BY ch2.created_at ASC LIMIT 1) as title")
            )
            ->groupBy('conversation_id')
            ->orderByDesc('last_message')
            ->limit(50)
            ->get();

        // Truncate titles
        $conversations = $conversations->map(function ($conv) {
            $conv->title = $conv->title ? mb_substr($conv->title, 0, 50) : 'New Chat';
            return $conv;
        });

        return response()->json(['conversations' => $conversations]);
    }

    /**
     * Get messages for a specific conversation
     */
    public function conversation(Request $request, string $conversationId)
    {
        $user = Auth::user();

        $messages = DB::table('chat_history')
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversationId)
            ->select('role', 'content', 'model', 'created_at')
            ->orderBy('created_at')
            ->get();

        return response()->json(['messages' => $messages]);
    }

    /**
     * Delete a conversation
     */
    public function deleteConversation(Request $request, string $conversationId)
    {
        $user = Auth::user();

        DB::table('chat_history')
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversationId)
            ->delete();

        return response()->json(['success' => true]);
    }
}
