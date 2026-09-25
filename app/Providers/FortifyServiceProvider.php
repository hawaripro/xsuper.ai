<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Services\LoginAdmission;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            \Laravel\Fortify\Http\Requests\TwoFactorLoginRequest::class,
            \App\Http\Requests\NativeTwoFactorLoginRequest::class,
        );
    }

    public function boot(): void
    {
        // Fortify action classes
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::authenticateUsing(function (Request $request) {
            $provider = Auth::guard(config('fortify.guard'))->getProvider();
            $credentials = $request->only(Fortify::username(), 'password');
            $user = $provider->retrieveByCredentials($credentials);
            if (! $user || ! $provider->validateCredentials($user, $credentials)) {
                return null;
            }
            if ($denied = app(LoginAdmission::class)->denial($request, $user, admitDevice: ! $user->hasEnabledTwoFactorAuthentication())) {
                throw new HttpResponseException($denied);
            }
            if (config('hashing.rehash_on_login', true) && method_exists($provider, 'rehashPasswordIfRequired')) {
                $provider->rehashPasswordIfRequired($user, $credentials);
            }

            return $user;
        });

        // SPA mode: views are disabled in config/fortify.php (views => false).
        // All auth screens are the React SPA; point the reset email at its route.
        ResetPassword::createUrlUsing(fn ($user, string $token) => url('/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset())));

        // Rate limiting for login attempts
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(
                Str::lower($request->input(Fortify::username())).'|'.$request->ip()
            );

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Rate limiting for two-factor authentication. Fall back to the client IP
        // when there is no pending challenge so requests are not bucketed under null.
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id') ?: 'ip:'.$request->ip());
        });

        // SPA login endpoint: brute-force protection per account and address without blocking normal retries.
        RateLimiter::for('api-login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return Limit::perMinute(8)->by($throttleKey);
        });
    }
}
