<?php

namespace App\Services;

use App\Mail\EmailOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        if ($user->email_verified_at !== null) {
            return false;
        }
        if ($user->email_otp_sent_at !== null && $user->email_otp_sent_at->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            throw ValidationException::withMessages([
                'code' => 'Tunggu sebentar sebelum meminta kode baru.',
            ]);
        }

        $code = (string) random_int(100000, 999999);
        $user->forceFill([
            'email_otp_hash' => Hash::make($code),
            'email_otp_expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            'email_otp_sent_at' => now(),
            'email_otp_attempts' => 0,
        ])->save();

        Mail::to($user->email)->send(new EmailOtp($code));

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
        if (! Hash::check(trim($code), $current->email_otp_hash)) {
            // The failed attempt must survive the thrown validation error.
            User::query()->whereKey($current->id)->increment('email_otp_attempts');
            throw ValidationException::withMessages(['code' => 'Kode aktivasi salah.']);
        }

        DB::transaction(function () use ($current): void {
            User::query()->lockForUpdate()->findOrFail($current->id)->forceFill([
                'email_verified_at' => now(),
                'email_otp_hash' => null,
                'email_otp_expires_at' => null,
                'email_otp_sent_at' => null,
                'email_otp_attempts' => 0,
            ])->save();
        });
    }
}
