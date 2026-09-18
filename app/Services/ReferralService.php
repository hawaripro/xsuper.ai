<?php

namespace App\Services;

use App\Models\DurationOrder;
use App\Models\Notification;
use App\Models\Referral;
use App\Models\ReferralProgramSetting;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class ReferralService
{
    public const SESSION_KEY = 'referral.code';

    public function __construct(private readonly AuditService $audit) {}

    public function capture(Request $request, string $code): string
    {
        $this->ensureEnabled();

        $code = $this->normalizeCode($code);
        $referrer = User::query()->where('referral_code', $code)->first();

        if (! $referrer) {
            throw ValidationException::withMessages([
                'code' => 'Kode referral tidak valid.',
            ]);
        }

        $user = $request->user();

        if ($user?->is($referrer)) {
            throw ValidationException::withMessages([
                'code' => 'Anda tidak dapat menggunakan kode referral sendiri.',
            ]);
        }

        if ($user) {
            $existing = Referral::query()->where('referred_id', $user->id)->first();

            if ($existing && $existing->referrer_id !== $referrer->id) {
                throw ValidationException::withMessages([
                    'code' => 'Akun ini sudah memiliki referrer.',
                ]);
            }
        }

        $request->session()->put(self::SESSION_KEY, $code);

        return $code;
    }

    public function attribute(User $user, ?Request $request = null): ?Referral
    {
        if (! $this->enabled()) {
            return null;
        }

        $request ??= request();
        $code = $request->session()->get(self::SESSION_KEY)
            ?: $request->cookie($this->cookieName());

        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        $code = $this->normalizeCode($code);
        $referrer = User::query()->where('referral_code', $code)->first();

        $request->session()->forget(self::SESSION_KEY);
        Cookie::queue(Cookie::forget($this->cookieName()));

        if (! $referrer || $referrer->is($user)) {
            return null;
        }

        return DB::transaction(function () use ($user, $referrer, $code): ?Referral {
            $lockedUser = User::query()->lockForUpdate()->find($user->id);

            if (! $lockedUser || $lockedUser->is($referrer)) {
                return null;
            }

            $existing = Referral::query()->where('referred_id', $lockedUser->id)->first();

            if ($existing) {
                return $existing->referrer_id === $referrer->id ? $existing : null;
            }

            return Referral::create([
                'referrer_id' => $referrer->id,
                'referred_id' => $lockedUser->id,
                'code' => $code,
                'status' => 'attributed',
                'attributed_at' => now(),
            ]);
        });
    }

    public function rewardFirstPurchase(DurationOrder $order, ?User $actor = null): bool
    {
        $rewardDays = $this->rewardDays();

        if (! $this->enabled() || $rewardDays < 1) {
            return false;
        }

        return DB::transaction(function () use ($order, $actor, $rewardDays): bool {
            $order = DurationOrder::query()->lockForUpdate()->find($order->id);

            if (! $order || $order->status !== 'approved' || ! $order->approved_at || $order->package === 'manual' || (float) $order->price <= 0) {
                return false;
            }

            $referral = Referral::query()
                ->where('referred_id', $order->user_id)
                ->lockForUpdate()
                ->first();

            if (! $referral || $referral->status === 'qualified') {
                return false;
            }

            if (DurationOrder::query()
                ->where('user_id', $order->user_id)
                ->where('status', 'approved')
                ->where('package', '!=', 'manual')
                ->where('price', '>', 0)
                ->whereKeyNot($order)
                ->exists()) {
                return false;
            }

            $existingRewards = ReferralReward::query()
                ->where('referral_id', $referral->id)
                ->lockForUpdate()
                ->get(['id'])
                ->count();

            if ($existingRewards > 0) {
                if ($existingRewards !== 2) {
                    throw new LogicException('Referral reward ledger is incomplete.');
                }

                return false;
            }

            $users = User::query()
                ->whereKey([$referral->referrer_id, $referral->referred_id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $referrer = $users->get($referral->referrer_id);
            $referred = $users->get($referral->referred_id);

            if (! $referrer || ! $referred || $referrer->is($referred)) {
                throw new LogicException('Referral participants are invalid.');
            }

            $this->awardDuration($referral, $referrer, 'referrer', $rewardDays);
            $this->awardDuration($referral, $referred, 'referred', $rewardDays);

            $referral->update([
                'status' => 'qualified',
                'qualified_at' => now(),
            ]);

            $auditActor = $actor
                ?? ($order->approved_by ? User::query()->find($order->approved_by) : null)
                ?? $referred;

            $this->audit->record($auditActor, 'referral.rewarded', $referral, [
                'order_id' => $order->id,
                'reward_days' => $rewardDays,
                'referrer_id' => $referrer->id,
                'referred_id' => $referred->id,
            ]);

            return true;
        });
    }

    public function enabled(): bool
    {
        return $this->settings()?->enabled ?? (bool) config('referrals.enabled', true);
    }

    public function rewardDays(): int
    {
        return max(0, $this->settings()?->reward_days ?? (int) config('referrals.reward_days', 3));
    }

    public function updateConfiguration(User $actor, bool $enabled, int $rewardDays): ReferralProgramSetting
    {
        return DB::transaction(function () use ($actor, $enabled, $rewardDays): ReferralProgramSetting {
            $settings = ReferralProgramSetting::query()->updateOrCreate(
                ['id' => ReferralProgramSetting::SINGLETON_ID],
                [
                    'enabled' => $enabled,
                    'reward_days' => $rewardDays,
                    'updated_by' => $actor->id,
                ],
            );

            $this->audit->record($actor, 'referral.configuration.updated', $settings, [
                'enabled' => $enabled,
                'reward_days' => $rewardDays,
            ]);

            return $settings;
        });
    }

    private function settings(): ?ReferralProgramSetting
    {
        return ReferralProgramSetting::query()->find(ReferralProgramSetting::SINGLETON_ID);
    }

    public function cookieName(): string
    {
        return (string) config('referrals.cookie_name', 'ultrai_referral');
    }

    private function awardDuration(Referral $referral, User $user, string $role, int $days): void
    {
        $base = $user->expires_at && $user->expires_at->isFuture()
            ? $user->expires_at->copy()
            : now();

        $user->forceFill(['expires_at' => $base->addDays($days)])->save();

        ReferralReward::create([
            'referral_id' => $referral->id,
            'user_id' => $user->id,
            'kind' => 'duration',
            'days' => $days,
            'wallet_microusd' => 0,
            'reference' => "referral:{$referral->id}:first-purchase:{$role}",
            'awarded_at' => now(),
        ]);

        Notification::create([
            'user_id' => $user->id,
            'kind' => 'referral_reward',
            'title' => 'Bonus referral diterima',
            'body' => "Masa aktif akun Anda bertambah {$days} hari dari program referral.",
            'action_url' => '/referral',
            'metadata' => [
                'referral_id' => $referral->id,
                'days' => $days,
                'role' => $role,
            ],
        ]);
    }

    private function ensureEnabled(): void
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages([
                'code' => 'Program referral sedang tidak aktif.',
            ]);
        }
    }

    private function normalizeCode(string $code): string
    {
        return Str::upper(trim($code));
    }
}
