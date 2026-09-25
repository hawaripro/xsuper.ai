<?php

namespace App\Models;

use App\Services\EmailIntelligence;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use LogicException;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    // Default permissions for new members
    const DEFAULT_PERMISSIONS = [
        'chat' => true,
        'chat_history' => true,
        'image_generator' => true,
        'video_generator' => true,
        'audio_generator' => true,
        'video_downloader' => true,
        'media_converter' => true,
        'ai_api' => true,
    ];

    protected $fillable = [
        'name',
        'email',
        'email_provider',
        'password',
        'role',
        'avatar',
        'is_active',
        'expires_at',
        'permissions',
        'google_id',
        'onboarding_mode',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
        'two_factor_confirmed_at',
        'email_otp_hash',
        'email_otp_expires_at',
        'email_otp_sent_at',
        'email_otp_attempts',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'email_otp_expires_at' => 'datetime',
            'email_otp_sent_at' => 'datetime',
            'email_otp_attempts' => 'integer',
            'permissions' => 'array',
        ];
    }
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! $user->referral_code) {
                $user->referral_code = static::newReferralCode();
            }
        });

        static::updating(function (User $user): void {
            if ($user->isDirty('referral_code') && $user->getOriginal('referral_code') !== null) {
                throw new LogicException('Referral codes are immutable.');
            }
        });
    }

    protected function getDirtyForUpdate(): array
    {
        if (! $this->isDirty('email')) {
            return parent::getDirtyForUpdate();
        }

        $invalidated = [
            'email_verified_at' => null,
            'email_otp_hash' => null,
            'email_otp_expires_at' => null,
            'email_otp_sent_at' => null,
            'email_otp_attempts' => 0,
            'email_provider' => app(EmailIntelligence::class)->provider($this->email),
        ];
        $this->forceFill($invalidated);

        // Include every proof field in the same UPDATE even if this model's old
        // snapshot already held null: a concurrent verifier/resend may have changed it.
        return array_merge(parent::getDirtyForUpdate(), $invalidated);
    }

    private static function newReferralCode(): string
    {
        do {
            $code = 'UTR-'.Str::upper(bin2hex(random_bytes(6)));
        } while (static::query()->where('referral_code', $code)->exists());

        return $code;
    }

    /** Replace a credential and revoke existing browser sessions, including legacy sessions without a hash. */
    public function replacePassword(#[\SensitiveParameter] string $password): void
    {
        $this->getConnection()->transaction(function () use ($password): void {
            $this->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table(config('session.table', 'sessions'))
                    ->where('user_id', $this->getAuthIdentifier())
                    ->delete();
            }
        });
    }


    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isMember(): bool
    {
        return $this->role === 'member';
    }

    /** Membership state for display and segments only; expiry never blocks usage (balance does). */
    public function isExpired(): bool
    {
        if ($this->isAdmin()) return false;
        if (!$this->expires_at) return false; // null = unlimited
        return $this->expires_at->isPast();
    }

    /** Membership = the bonus period: an end date that is still in the future. */
    public function hasActiveMembership(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public function membershipEndsAt(): ?Carbon
    {
        return $this->expires_at?->copy();
    }

    /**
     * Member-facing membership state (`/api/user`, `/api/dashboard`, admin users list).
     *
     * @return array{active: bool, expires_at: ?string, days_remaining: ?int}
     */
    public function membershipSummary(): array
    {
        $endsAt = $this->membershipEndsAt();
        $active = $this->hasActiveMembership();

        return [
            'active' => $active,
            'expires_at' => $endsAt?->toISOString(),
            'days_remaining' => $endsAt === null ? null : ($active ? (int) now()->diffInDays($endsAt) : 0),
        ];
    }

    public function daysRemaining(): ?int
    {
        if ($this->isAdmin()) return null;
        if (!$this->expires_at) return null; // null = unlimited
        if ($this->expires_at->isPast()) return 0;
        return (int) now()->diffInDays($this->expires_at);
    }

    public function hasPermission(string $key): bool
    {
        if ($this->isAdmin()) return true;

        // Stored permissions overlay the defaults instead of replacing them:
        // a sparse map used to silently revoke every unlisted default-true
        // permission, and any newly introduced key instantly 403'd existing
        // accounts until a data migration backfilled it.
        $perms = array_merge(self::DEFAULT_PERMISSIONS, (array) ($this->permissions ?? []));

        return (bool) ($perms[$key] ?? false);
    }

    public function getPermissions(): array
    {
        if ($this->isAdmin()) {
            return array_fill_keys(array_keys(self::DEFAULT_PERMISSIONS), true);
        }

        return array_intersect_key(
            array_merge(self::DEFAULT_PERMISSIONS, $this->permissions ?? []),
            self::DEFAULT_PERMISSIONS,
        );
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    public function referralAttribution(): HasOne
    {
        return $this->hasOne(Referral::class, 'referred_id');
    }

    public function referralRewards(): HasMany
    {
        return $this->hasMany(ReferralReward::class);
    }

    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function chatConversations()
    {
        return $this->hasMany(ChatConversation::class);
    }
}
