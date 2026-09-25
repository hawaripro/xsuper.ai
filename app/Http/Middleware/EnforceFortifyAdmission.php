<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\LoginAdmission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceFortifyAdmission
{
    public function __construct(private readonly LoginAdmission $admission) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Denied accounts must always be able to sign out. Guest registration and
        // recovery requests have no authenticated principal and proceed normally.
        if ($request->routeIs('logout')) {
            return $next($request);
        }

        $challenge = $request->routeIs('two-factor.login.store');
        $user = $challenge
            ? User::find($request->session()->get('login.id'))
            : $request->user();
        if ($challenge && (! $user || ! $user->hasEnabledTwoFactorAuthentication())) {
            $request->session()->forget(['login.id', 'login.remember']);

            return response()->json(['message' => 'Sesi login sudah berakhir. Masuk kembali.'], 419);
        }

        if ($user && ($denied = $this->admission->denial($request, $user, admitDevice: ! $challenge))) {
            if ($challenge) {
                $request->session()->forget(['login.id', 'login.remember']);
            }

            return $denied;
        }

        return $next($request);
    }
}
