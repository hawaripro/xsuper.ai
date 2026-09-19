<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EmailIntelligence;
use App\Services\ReferralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Redirect to Google OAuth consent screen. `?intent=register` lets a brand
     * new Google account create a member during the callback.
     */
    public function redirect(Request $request)
    {
        $request->session()->put('oauth.intent', $request->query('intent') === 'register' ? 'register' : 'login');

        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle callback from Google. Existing accounts sign in; the register flow
     * (`?intent=register`) creates a member, blocking disposable inboxes.
     */
    public function callback(Request $request, ReferralService $referrals, EmailIntelligence $email)
    {
        $intent = $request->session()->pull('oauth.intent', 'login');

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            return redirect('/login?error=google_failed');
        }

        $address = (string) $googleUser->getEmail();

        // Find existing user by email or google_id.
        $user = User::where('email', $address)
            ->orWhere('google_id', $googleUser->getId())
            ->first();

        if (! $user) {
            // Only the register flow may create a new account from Google.
            if ($intent !== 'register') {
                return redirect('/login?error=not_registered');
            }

            if ($address === '' || $email->isDisposable($address)) {
                return redirect('/register?error=disposable_email');
            }

            $user = User::create([
                'name' => $googleUser->getName() ?: Str::before($address, '@'),
                'email' => $address,
                'email_provider' => $email->provider($address),
                'google_id' => $googleUser->getId(),
                'avatar' => $googleUser->getAvatar(),
                'password' => Hash::make(Str::random(40)),
                'role' => 'member',
            ]);

            $referrals->attribute($user, $request);

            Auth::login($user, true);

            return redirect('/dashboard');
        }

        // Update google_id if not set yet.
        if (! $user->google_id) {
            $user->update(['google_id' => $googleUser->getId()]);
        }
        if ($user->email_provider === null) {
            $user->update(['email_provider' => $email->provider($user->email)]);
        }
        $referrals->attribute($user, $request);

        Auth::login($user, true);

        return redirect('/dashboard');
    }
}
