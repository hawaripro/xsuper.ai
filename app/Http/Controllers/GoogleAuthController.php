<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EmailIntelligence;
use App\Services\EmailOtpService;
use App\Services\LoginAdmission;
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
    public function callback(Request $request, ReferralService $referrals, EmailIntelligence $email, EmailOtpService $otp, LoginAdmission $admission)
    {
        $intent = $request->session()->pull('oauth.intent', 'login');

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            return redirect('/login?error=google_failed');
        }

        $address = (string) $googleUser->getEmail();
        $subject = (string) $googleUser->getId();
        if ($subject === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return redirect('/login?error=google_failed');
        }
        $claims = $googleUser->getRaw();
        $verifiedEmail = ($claims['email_verified'] ?? false) === true
            && ($claims['email'] ?? null) === $address;

        // The provider subject is the identity. An email-only match may link only
        // when both sides have proven that address, never an unverified registration.
        $user = User::where('google_id', $subject)->first();
        if (! $user) {
            $user = User::where('email', $address)->first();
            if ($user && ($user->email_verified_at === null || ! $verifiedEmail || $user->google_id !== null)) {
                return redirect('/login?error=account_link_required');
            }
        }

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
                'google_id' => $subject,
                'avatar' => $googleUser->getAvatar(),
                'password' => Hash::make(Str::random(40)),
                'role' => 'member',
            ]);

            if ($verifiedEmail) {
                $user->forceFill(['email_verified_at' => now()])->save();
            } else {
                $otp->sendSilently($user);
            }
        }

        $requiresTwoFactor = $user->hasEnabledTwoFactorAuthentication();
        if ($admission->denial($request, $user, admitDevice: ! $requiresTwoFactor)) {
            return redirect('/login?error=account_restricted');
        }

        // Attach only the safely resolved provider identity.
        if (! $user->google_id) {
            $user->update(['google_id' => $subject]);
        }
        if ($user->email_provider === null) {
            $user->update(['email_provider' => $email->provider($user->email)]);
        }
        $referrals->attribute($user, $request);

        if ($requiresTwoFactor) {
            $admission->challenge($request, $user, true);

            return redirect('/login?two_factor=1');
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect('/dashboard');
    }
}
