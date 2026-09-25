<?php

namespace App\Http\Controllers\Api;

use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

class ProfileController extends Controller
{
    /**
     * Update profile info
     */
    public function update(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->save();

        return response()->json([
            'message' => 'Profil berhasil diperbarui',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->email_verified_at !== null,
                'role' => $user->role,
                'avatar' => $user->avatar,
                'created_at' => $user->created_at,
            ],
        ]);
    }

    /**
     * Update password
     */
    public function updatePassword(Request $request, UpdateUserPassword $updater)
    {
        $updater->update($request->user(), $request->all());

        return response()->json(['message' => 'Password berhasil diperbarui']);
    }

    /** Sends (or resends) the email activation code. */
    public function sendEmailOtp(Request $request, \App\Services\EmailOtpService $otp)
    {
        $user = $request->user();
        if ($user->email_verified_at !== null) {
            return response()->json(['message' => 'Email sudah aktif.', 'verified' => true]);
        }
        $otp->send($user);

        return response()->json(['message' => 'Kode aktivasi dikirim ke email Anda.', 'verified' => false]);
    }

    /** Confirms the activation code and unlocks the account. */
    public function verifyEmailOtp(Request $request, \App\Services\EmailOtpService $otp)
    {
        $request->validate(['code' => 'required|string|max:12']);
        $otp->verify($request->user(), (string) $request->input('code'));

        return response()->json(['message' => 'Email berhasil diaktifkan.', 'verified' => true]);
    }

    /**
     * Account security overview: two-factor state without exposing secrets.
     */
    public function security(Request $request)
    {
        return response()->json($this->securityPayload($request->user()));
    }

    /**
     * Start two-factor enrolment: generates a secret and returns the QR code for an authenticator app.
     */
    public function enableTwoFactor(Request $request, EnableTwoFactorAuthentication $enable)
    {
        $user = $this->confirmedUser($request);

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['message' => 'Autentikasi dua langkah sudah aktif.'], 409);
        }

        $enable($user, true);

        return response()->json($this->securityPayload($user->fresh()), 201);
    }

    /**
     * Confirm enrolment with a code from the authenticator app and hand out recovery codes once.
     */
    public function confirmTwoFactor(Request $request, ConfirmTwoFactorAuthentication $confirm)
    {
        $request->validate(['code' => 'required|string|max:12']);
        $user = $request->user();

        if ($user->two_factor_secret === null) {
            return response()->json(['message' => 'Mulai pengaktifan dua langkah terlebih dahulu.'], 409);
        }

        try {
            $confirm($user, trim((string) $request->input('code')));
        } catch (ValidationException) {
            throw ValidationException::withMessages(['code' => 'Kode autentikasi tidak valid. Periksa jam perangkat dan coba lagi.']);
        }

        $user = $user->fresh();

        return response()->json($this->securityPayload($user) + ['recovery_codes' => $user->recoveryCodes()]);
    }

    public function regenerateRecoveryCodes(Request $request, GenerateNewRecoveryCodes $generate)
    {
        $user = $this->confirmedUser($request);

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['message' => 'Autentikasi dua langkah belum aktif.'], 409);
        }

        $generate($user);
        $user = $user->fresh();

        return response()->json($this->securityPayload($user) + ['recovery_codes' => $user->recoveryCodes()]);
    }

    public function disableTwoFactor(Request $request, DisableTwoFactorAuthentication $disable)
    {
        $user = $this->confirmedUser($request);
        $disable($user);

        return response()->json($this->securityPayload($user->fresh()));
    }

    private function confirmedUser(Request $request): User
    {
        $request->validate(['password' => 'required|string']);
        $user = $request->user();

        if (! Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => 'Password saat ini salah.']);
        }

        return $user;
    }

    private function securityPayload(User $user): array
    {
        $enabled = $user->hasEnabledTwoFactorAuthentication();
        $pending = ! $enabled && $user->two_factor_secret !== null;

        return [
            'two_factor' => [
                'enabled' => $enabled,
                'pending' => $pending,
                'confirmed_at' => $user->two_factor_confirmed_at?->toISOString(),
                'recovery_codes_remaining' => $enabled ? count($user->recoveryCodes()) : 0,
                'qr_svg' => $pending ? $user->twoFactorQrCodeSvg() : null,
                'secret' => $pending ? decrypt($user->two_factor_secret) : null,
            ],
        ];
    }
}
