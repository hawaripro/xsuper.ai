<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserToken;
use App\Models\TokenTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TokenController extends Controller
{
    public function balance()
    {
        $balance = UserToken::getBalance(Auth::id());
        return response()->json(['balance' => $balance]);
    }

    public function history()
    {
        $transactions = TokenTransaction::where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['type', 'amount', 'balance_after', 'description', 'created_at']);

        return response()->json(['transactions' => $transactions]);
    }

    // Admin: topup tokens for a user
    public function topup(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|integer|min:1',
            'description' => 'nullable|string|max:255',
        ]);

        UserToken::topup(
            $validated['user_id'],
            $validated['amount'],
            $validated['description'] ?? 'Top up by admin'
        );

        return response()->json([
            'message' => 'Token berhasil ditambahkan',
            'balance' => UserToken::getBalance($validated['user_id']),
        ]);
    }
}
