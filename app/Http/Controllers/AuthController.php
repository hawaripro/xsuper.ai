<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Login via API (JSON response for SPA)
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            $user = Auth::user();

            $response = response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role,
                    'avatar' => $user->avatar,
                ],
            ]);

            // Set dash_token cookie for admin users (allows access to dash.ultrai.id)
            if ($user->isAdmin()) {
                $token = hash('sha256', $user->id . '|' . config('app.key') . '|dash');
                $response->withCookie(cookie('dash_token', $token, 120, '/', '.ultrai.id', true, true, false, 'Lax'));
            }

            return $response;
        }

        return response()->json([
            'message' => 'Email atau password salah.',
        ], 422);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'member',
        ]);

        Auth::login($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'redirect' => '/dashboard',
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Clear dash_token cookie
        return response()->json(['message' => 'Logged out'])
            ->withCookie(cookie()->forget('dash_token', '/', '.ultrai.id'));
    }

    public function user(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(null, 401);
        }

        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'avatar' => $user->avatar,
        ];

        // Tambah info expiry untuk member
        if (!$user->isAdmin()) {
            $data['expires_at'] = $user->expires_at?->toISOString();
            $data['days_remaining'] = $user->daysRemaining();
            $data['is_expired'] = $user->isExpired();
        }

        return response()->json($data);
    }
}
