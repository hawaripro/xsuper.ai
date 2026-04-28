<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'stats' => [
                    'total_users' => User::count(),
                    'active_users' => User::where('is_active', true)->count(),
                    'total_conversations' => ChatConversation::count(),
                    'total_messages' => ChatMessage::count(),
                    'total_tokens' => ChatMessage::sum('tokens_used'),
                ],
                'recent_users' => User::latest()->take(10)->get(['id', 'name', 'email', 'role', 'is_active', 'created_at']),
            ]);
        }

        return view('app');
    }

    public function users(Request $request)
    {
        $query = User::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        $users = $query->latest()->paginate(20);

        return response()->json($users);
    }

    public function updateUser(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'role' => 'sometimes|in:admin,member',
            'is_active' => 'sometimes|boolean',
            'password' => 'sometimes|min:8',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        return response()->json(['message' => 'User updated', 'user' => $user]);
    }

    public function deleteUser(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Cannot delete yourself'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }
}
