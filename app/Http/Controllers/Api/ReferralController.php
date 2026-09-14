<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function __construct(private readonly ReferralService $referrals)
    {
    }

    public function capture(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);
        $code = $this->referrals->capture($request, $validated['code']);

        return response()
            ->json(['captured' => true])
            ->withCookie(cookie(
                $this->referrals->cookieName(),
                $code,
                max(1, (int) config('referrals.cookie_minutes', 43200)),
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax',
            ));
    }

    public function memberStats(Request $request): JsonResponse
    {
        $user = $request->user();
        $referrals = Referral::query()
            ->where('referrer_id', $user->id)
            ->with('referred:id,name')
            ->latest('attributed_at')
            ->get();

        return response()->json([
            'program' => [
                'enabled' => $this->referrals->enabled(),
                'reward_days' => $this->referrals->rewardDays(),
            ],
            'referral' => [
                'code' => $user->referral_code,
                'link' => url('/?ref='.rawurlencode($user->referral_code)),
            ],
            'stats' => [
                'invited' => $referrals->count(),
                'attributed' => $referrals->where('status', 'attributed')->count(),
                'qualified' => $referrals->where('status', 'qualified')->count(),
                'days_earned' => (int) ReferralReward::query()
                    ->where('user_id', $user->id)
                    ->sum('days'),
            ],
            'recent_referrals' => $referrals->take(20)->map(fn (Referral $referral): array => [
                'id' => $referral->id,
                'name' => $referral->referred?->name,
                'status' => $referral->status,
                'attributed_at' => $referral->attributed_at,
                'qualified_at' => $referral->qualified_at,
            ])->values(),
        ]);
    }

    public function adminStatus(): JsonResponse
    {
        return response()->json([
            'program' => [
                'status' => $this->referrals->enabled() ? 'active' : 'disabled',
                'enabled' => $this->referrals->enabled(),
                'reward_days' => $this->referrals->rewardDays(),
            ],
            'totals' => [
                'referrals' => Referral::query()->count(),
                'attributed' => Referral::query()->where('status', 'attributed')->count(),
                'qualified' => Referral::query()->where('status', 'qualified')->count(),
                'rewards' => ReferralReward::query()->count(),
                'days_awarded' => (int) ReferralReward::query()->sum('days'),
            ],
        ]);
    }

    public function updateConfiguration(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reward_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $settings = $this->referrals->updateConfiguration(
            $request->user(),
            $validated['enabled'],
            $validated['reward_days'],
        );

        return response()->json([
            'program' => [
                'status' => $settings->enabled ? 'active' : 'disabled',
                'enabled' => $settings->enabled,
                'reward_days' => $settings->reward_days,
            ],
        ]);
    }
}
