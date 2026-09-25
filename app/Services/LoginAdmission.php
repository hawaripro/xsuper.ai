<?php

namespace App\Services;

use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\TrackDevice;
use App\Models\SecuritySetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginAdmission
{
    public function denial(Request $request, User $user, bool $admitDevice = false): ?JsonResponse
    {
        // Enrollment remains reachable: mandatory admin 2FA is a privileged-action
        // policy, whereas the admin network restriction applies to session admission.
        if ($user->isAdmin() && ! SecuritySetting::current()->ipAllowed($request->ip())) {
            return response()->json([
                'message' => 'Alamat IP Anda tidak diizinkan untuk akses admin.',
                'code' => 'ip_not_allowed',
            ], 403);
        }

        // Pre-factor checks resolve existing device policy read-only. Only a fully
        // authenticated candidate may allocate a slot or record device activity.
        // The clone shares the session so completed admissions bind their identity.
        $candidate = clone $request;
        $candidate->setUserResolver(fn () => $user);
        $candidate->headers->set('Accept', 'application/json');
        $response = app(EnsureActive::class)->handle($candidate, fn (Request $checked) =>
            app(TrackDevice::class)->handle($checked, fn () => response()->noContent(), $admitDevice));

        return $response instanceof JsonResponse ? $response : null;
    }

    public function challenge(Request $request, User $user, bool $remember): void
    {
        if (Auth::check()) {
            Auth::logout();
        }
        $request->session()->regenerate();
        $request->session()->put([
            'login.id' => $user->getKey(),
            'login.remember' => $remember,
        ]);
    }
}
