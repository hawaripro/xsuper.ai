<?php

namespace App\Services;

use App\Models\DurationOrder;
use App\Models\Notification;
use App\Models\Referral;
use App\Models\ReferralProgramSetting;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\UserDevice;
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

    /**
     * Lenient capture used for `?ref=CODE` landing links and the register form:
     * remembers a valid referral code in the session and a durable cookie without
     * throwing on unknown or self codes, so the referrer resolves at signup time.
     */
    public function remember(Request $request, ?string $code): ?string
    {
        if (! $this->enabled() || ! is_string($code) || trim($code) === '') {
            return null;
        }

        $code = $this->normalizeCode($code);
        $referrer = User::query()->where('referral_code', $code)->first();

        if (! $referrer || $request->user()?->is($referrer)) {
            return null;
        }

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $code);
        }
        Cookie::queue($this->cookieName(), $code, max(1, (int) config('referrals.cookie_minutes', 43200)), '/', null, $request->isSecure(), true, false, 'Lax');

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

        $ip = (string) $request->ip();
        $deviceHash = self::deviceSignature($request->userAgent());

        return DB::transaction(function () use ($user, $referrer, $code, $ip, $deviceHash): ?Referral {
            $lockedUser = User::query()->lockForUpdate()->find($user->id);

            if (! $lockedUser || $lockedUser->is($referrer)) {
                return null;
            }

            $existing = Referral::query()->where('referred_id', $lockedUser->id)->first();

            if ($existing) {
                return $existing->referrer_id === $referrer->id ? $existing : null;
            }

            $risk = $this->assessRisk($referrer, $ip, $deviceHash);
            $referral = Referral::create([
                'referrer_id' => $referrer->id,
                'referred_id' => $lockedUser->id,
                'code' => $code,
                'status' => $risk['level'] === 'clear' ? 'attributed' : 'flagged',
                'attributed_at' => now(),
                'referred_ip' => $ip !== '' ? mb_substr($ip, 0, 45) : null,
                'referred_device_hash' => $deviceHash,
                'risk_level' => $risk['level'],
                'risk_reasons' => $risk['reasons'] ?: null,
            ]);
            if ($risk['level'] !== 'clear') {
                $this->audit->record($lockedUser, 'referral.flagged', $referral, ['referrer_id' => $referrer->id, 'reasons' => $risk['reasons']]);
            }

            return $referral;
        });
    }

    /**
     * Duplicate-account detection: the referred person must not sign up from the
     * referrer's own network or browser. Signals come from the device tracker,
     * which records every authenticated request, so a shared address seen later
     * (both accounts used from the same place) also holds the reward.
     */
    public function assessRisk(User $referrer, ?string $ip, ?string $deviceHash, ?User $referred = null): array
    {
        $reasons = [];
        $devices = UserDevice::query()->where('user_id', $referrer->id)->get(['ip_address', 'user_agent']);
        $referrerIps = $devices->pluck('ip_address')->filter()->map(fn (string $address): string => trim($address))->unique();
        $referrerAgents = $devices->pluck('user_agent')->filter()->map(fn (string $agent): string => self::deviceSignature($agent))->unique();
        $ips = collect([$ip])->filter();
        $agents = collect([$deviceHash])->filter();
        if ($referred) {
            $own = UserDevice::query()->where('user_id', $referred->id)->get(['ip_address', 'user_agent']);
            $ips = $ips->merge($own->pluck('ip_address')->filter())->unique();
            $agents = $agents->merge($own->pluck('user_agent')->filter()->map(fn (string $agent): string => self::deviceSignature($agent)))->unique();
        }
        $ips = $ips->reject(fn (string $address): bool => in_array($address, ['127.0.0.1', '::1'], true) && ! app()->environment('testing'));
        if ($ips->intersect($referrerIps)->isNotEmpty()) {
            $reasons[] = 'shared_ip';
        }
        if ($agents->intersect($referrerAgents)->isNotEmpty() && in_array('shared_ip', $reasons, true)) {
            $reasons[] = 'shared_device';
        }

        return ['level' => $reasons === [] ? 'clear' : (in_array('shared_device', $reasons, true) ? 'high' : 'review'), 'reasons' => $reasons];
    }

    public static function deviceSignature(?string $userAgent): ?string
    {
        $agent = trim((string) $userAgent);

        return $agent === '' ? null : hash('sha256', $agent);
    }

    public function review(Referral $referral, User $reviewer, string $decision, ?string $note = null): Referral
    {
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve or reject.']);
        }
        $referral = DB::transaction(function () use ($referral, $reviewer, $decision, $note): Referral {
            $locked = Referral::query()->lockForUpdate()->findOrFail($referral->id);
            if (! in_array($locked->status, ['flagged', 'attributed', 'rejected'], true)) {
                throw ValidationException::withMessages(['decision' => 'A qualified referral can no longer be reviewed.']);
            }
            $locked->update([
                'status' => $decision === 'approve' ? 'attributed' : 'rejected',
                'reviewed_at' => now(), 'reviewed_by' => $reviewer->id,
                'review_note' => $note !== null ? mb_substr(trim($note), 0, 240) : null,
            ]);
            $this->audit->record($reviewer, 'referral.'.($decision === 'approve' ? 'released' : 'rejected'), $locked, ['reasons' => $locked->risk_reasons, 'note' => $locked->review_note]);

            return $locked;
        });
        if ($decision === 'approve') {
            $order = DurationOrder::query()->where('user_id', $referral->referred_id)->where('status', 'approved')
                ->where('package', '!=', 'manual')->where('price', '>', 0)->orderBy('approved_at')->first();
            if ($order) {
                $this->rewardFirstPurchase($order, $reviewer, true);
            }
        }

        return $referral->refresh();
    }

    public function rewardFirstPurchase(DurationOrder $order, ?User $actor = null, bool $reviewed = false): bool
    {
        $rewardDays = $this->rewardDays();

        if (! $this->enabled() || $rewardDays < 1) {
            return false;
        }

        return DB::transaction(function () use ($order, $actor, $rewardDays, $reviewed): bool {
            $order = DurationOrder::query()->lockForUpdate()->find($order->id);

            if (! $order || $order->status !== 'approved' || ! $order->approved_at || $order->package === 'manual' || (float) $order->price <= 0) {
                return false;
            }

            $referral = Referral::query()
                ->where('referred_id', $order->user_id)
                ->lockForUpdate()
                ->first();

            if (! $referral || $referral->status !== 'attributed') {
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

            // Re-check with everything the device tracker has seen since sign-up. An
            // admin approval skips this: the reviewer already weighed the signals.
            if (! $reviewed && $referral->reviewed_at === null) {
                $risk = $this->assessRisk($referrer, $referral->referred_ip, $referral->referred_device_hash, $referred);
                if ($risk['level'] !== 'clear') {
                    $referral->update(['status' => 'flagged', 'risk_level' => $risk['level'], 'risk_reasons' => $risk['reasons']]);
                    $this->audit->record($actor ?? $referred, 'referral.flagged', $referral, ['order_id' => $order->id, 'reasons' => $risk['reasons']]);

                    return false;
                }
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
