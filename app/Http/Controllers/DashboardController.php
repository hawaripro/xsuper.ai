<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            $user = $request->user();
            $conversations = $user->chatConversations()->count();
            $messages = ChatMessage::where('user_id', $user->id)->count();
            $tokensUsed = ChatMessage::where('user_id', $user->id)->sum('tokens_used');

            return response()->json([
                'stats' => [
                    'conversations' => $conversations,
                    'messages' => $messages,
                    'tokens_used' => $tokensUsed,
                ],
                'recent_conversations' => $user->chatConversations()
                    ->latest()
                    ->take(5)
                    ->get(),
            ]);
        }

        return view('app');
    }
}
