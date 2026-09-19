<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

class AuthController extends Controller
{
    public function __construct(private readonly ReferralService $referrals) {}

    /**
     * Login via API (JSON response for SPA)
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (! Auth::validate($credentials)) {
            return response()->json([
                'message' => 'Email atau password salah.',
            ], 422);
        }

        $user = Auth::getLastAttempted();

        if ($user->is_active === false && $user->role !== 'admin') {
            return response()->json([
                'message' => 'Akun Anda dinonaktifkan. Hubungi admin.',
            ], 403);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            // Credentials are correct but the session stays unauthenticated until a valid TOTP code arrives.
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => $request->boolean('remember'),
            ]);

            return response()->json(['two_factor' => true]);
        }

        Auth::login($user, $request->boolean('remember'));

        return $this->authenticated($request, $user);
    }

    /**
     * Complete a login that was paused by two-factor authentication.
     */
    public function twoFactorChallenge(Request $request, TwoFactorAuthenticationProvider $provider)
    {
        $request->validate([
            'code' => 'nullable|string|max:12',
            'recovery_code' => 'nullable|string|max:64',
        ]);

        $user = $request->session()->has('login.id')
            ? User::query()->find($request->session()->get('login.id'))
            : null;

        if (! $user || ! $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->forget(['login.id', 'login.remember']);

            return response()->json(['message' => 'Sesi login sudah berakhir. Masuk kembali.'], 419);
        }

        $code = trim((string) $request->input('code', ''));
        $recovery = trim((string) $request->input('recovery_code', ''));

        if ($recovery !== '') {
            $known = collect($user->recoveryCodes())->first(fn ($candidate) => hash_equals($candidate, $recovery));
            if ($known === null) {
                throw ValidationException::withMessages(['recovery_code' => 'Kode pemulihan tidak valid.']);
            }
            $user->replaceRecoveryCode($known);
        } elseif ($code === '' || ! $provider->verify(decrypt($user->two_factor_secret), $code)) {
            throw ValidationException::withMessages(['code' => 'Kode autentikasi tidak valid.']);
        }

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');
        Auth::login($user, $remember);

        return $this->authenticated($request, $user);
    }

    private function authenticated(Request $request, User $user)
    {
        $request->session()->regenerate();

        $response = response()->json([
            'csrf_token' => $request->session()->token(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'avatar' => $user->avatar,
            ],
        ]);

        // Random, hashed, expiring, per-login dash token stored server-side so it
        // can be revoked (logout/admin) — never derivable from APP_KEY.
        if ($user->isAdmin()) {
            $raw = \App\Models\DashToken::issueFor($user->id);
            $response->withCookie(cookie('dash_token', $raw, 10080, '/', '.xsuper.dev', true, true, false, 'Lax'));
        }

        return $response;
    }

    public function logout(Request $request)
    {
        $userId = $request->user()?->id;
        \App\Models\DashToken::revokeFor($userId);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Clear dash_token cookie
        return response()->json(['message' => 'Logged out'])
            ->withCookie(cookie()->forget('dash_token', '/', '.xsuper.dev'));
    }

    public function user(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(null, 401);
        }

        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'expires_at' => $user->expires_at?->toISOString(),
            'role' => $user->role,
            'avatar' => $user->avatar,
            'permissions' => $user->getPermissions(),
        ];

        if (! $user->isAdmin()) {
            $data['days_remaining'] = $user->daysRemaining();
            $data['is_expired'] = $user->isExpired();
        }

        return response()->json($data);
    }
}
