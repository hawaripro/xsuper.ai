<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Redirect to Google OAuth consent screen.
     */
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle callback from Google.
     * - Only allow login if user already exists in the system
     * - If email not registered → reject (admin must add user first)
     */
    public function callback(ReferralService $referrals)
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect('/login?error=google_failed');
        }

        // Find existing user by email or google_id
        $user = User::where('email', $googleUser->getEmail())
            ->orWhere('google_id', $googleUser->getId())
            ->first();

        if (!$user) {
            // User not registered — reject
            return redirect('/login?error=not_registered');
        }

        // Update google_id if not set yet
        if (!$user->google_id) {
            $user->update(['google_id' => $googleUser->getId()]);
        }
        $referrals->attribute($user, request());

        Auth::login($user, true);

        return redirect('/dashboard');
    }
}
