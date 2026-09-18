<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
        'model_original' => true,
        'model_authentic' => false,
        'model_codex' => false,
        'model_wavespeed' => false,
        'model_yepapi' => false,
        'model_canva' => false,
        'video_generator' => false,
        'audio_generator' => true,
        'video_downloader' => true,
        'media_converter' => true,
        'ai_api' => false,
        'ai_dashboard' => false,
        'ai_dashboard_official' => false,
    ];

    protected $fillable = [
        'name',
        'email',
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
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
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

    private static function newReferralCode(): string
    {
        do {
            $code = 'UTR-'.Str::upper(bin2hex(random_bytes(6)));
        } while (static::query()->where('referral_code', $code)->exists());

        return $code;
    }


    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isMember(): bool
    {
        return $this->role === 'member';
    }

    public function isExpired(): bool
    {
        if ($this->isAdmin()) return false;
        if (!$this->expires_at) return false; // null = unlimited
        return $this->expires_at->isPast();
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
        $perms = $this->permissions ?? self::DEFAULT_PERMISSIONS;
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

    public function getAllowedTiers(): array
    {
        if ($this->isAdmin()) return ['Standard', 'MAX', 'Codex', 'Wavespeed', 'YepAPI', 'Canva'];

        $tiers = [];
        if ($this->hasPermission('model_original')) $tiers[] = 'Standard';
        if ($this->hasPermission('model_authentic')) $tiers[] = 'MAX';
        if ($this->hasPermission('model_codex')) $tiers[] = 'Codex';
        if ($this->hasPermission('model_wavespeed')) $tiers[] = 'Wavespeed';
        if ($this->hasPermission('model_yepapi')) $tiers[] = 'YepAPI';
        if ($this->hasPermission('model_canva')) $tiers[] = 'Canva';
        return $tiers;
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
