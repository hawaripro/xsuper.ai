<?php

namespace App\Services;

use App\Mail\EmailOtp;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * One-time email activation codes: 6 digits, hashed at rest, 10-minute expiry,
 * 60-second resend window, and at most 5 attempts per code.
 */
class EmailOtpService
{
    public const RESEND_SECONDS = 60;

    public const EXPIRY_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /** Sends a fresh code. Returns false when inside the resend window. */
    public function send(User $user): bool
    {
        $current = User::query()->findOrFail($user->id);
        if ($current->email_verified_at !== null) {
            return false;
        }
        if ($current->email_otp_sent_at !== null && $current->email_otp_sent_at->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            throw ValidationException::withMessages([
                'code' => 'Tunggu sebentar sebelum meminta kode baru.',
            ]);
        }

        $code = (string) random_int(100000, 999999);
        // The salted hash is the code version. Do not attach it to an address changed
        // or a newer code issued after this snapshot was read.
        $stored = $this->pendingQuery($current)->update([
            'email_otp_hash' => Hash::make($code),
            'email_otp_expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            'email_otp_sent_at' => now(),
            'email_otp_attempts' => 0,
        ]);
        if ($stored !== 1) {
            throw ValidationException::withMessages(['code' => 'Email atau kode telah berubah. Minta kode baru.']);
        }

        // Deliver only to the recipient whose row/version was updated, never a refreshed address.
        Mail::to($current->email)->send(new EmailOtp($code));
        $user->refresh();

        return true;
    }

    /** Best-effort initial send that never breaks registration on mail failure. */
    public function sendSilently(User $user): void
    {
        try {
            $this->send($user);
        } catch (\Throwable $exception) {
            Log::warning('Email OTP could not be sent at registration.', ['user_id' => $user->id]);
        }
    }

    public function verify(User $user, string $code): void
    {
        $current = User::query()->findOrFail($user->id);
        if ($current->email_verified_at !== null) {
            return; // Already active: idempotent success.
        }
        if ($current->email_otp_hash === null || $current->email_otp_expires_at === null || $current->email_otp_expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => 'Kode sudah kedaluwarsa. Minta kode baru.']);
        }
        if ($current->email_otp_attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => 'Terlalu banyak percobaan. Minta kode baru.']);
        }
        $pending = $this->pendingQuery($current)
            ->where('email_otp_expires_at', '>', now())
            ->where('email_otp_attempts', '<', self::MAX_ATTEMPTS);

        if (! Hash::check(trim($code), $current->email_otp_hash)) {
            // Count only against this code/version, atomically capped; do not roll it back with the error.
            $pending->increment('email_otp_attempts');
            throw ValidationException::withMessages(['code' => 'Kode aktivasi salah.']);
        }

        // Check-and-consume in one statement: an intervening address change, resend,
        // expiry, or exhausted attempt budget must never verify a different snapshot.
        $consumed = $pending->update([
            'email_verified_at' => now(),
            'email_otp_hash' => null,
            'email_otp_expires_at' => null,
            'email_otp_sent_at' => null,
            'email_otp_attempts' => 0,
        ]);
        if ($consumed !== 1) {
            throw ValidationException::withMessages(['code' => 'Kode sudah kedaluwarsa. Minta kode baru.']);
        }
    }

    private function pendingQuery(User $current): Builder
    {
        return User::query()
            ->whereKey($current->id)
            ->where('email', $current->email)
            ->where('email_otp_hash', $current->email_otp_hash)
            ->whereNull('email_verified_at');
    }
}
