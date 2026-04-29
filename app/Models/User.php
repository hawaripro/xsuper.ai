<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

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
        'ai_api' => false,
        'ai_dashboard' => false,
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
        return array_merge(self::DEFAULT_PERMISSIONS, $this->permissions ?? []);
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
        return $tiers;
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
