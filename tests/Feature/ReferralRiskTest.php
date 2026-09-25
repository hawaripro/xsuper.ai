<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DurationOrder;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralRiskTest extends TestCase
{
    use RefreshDatabase;

    private const REFERRER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/152.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        config(['referrals.enabled' => true, 'referrals.reward_days' => 3]);
    }

    private function referrerWithDevice(string $ip): User
    {
        $referrer = User::factory()->create(['expires_at' => now()->addDays(10)]);
        UserDevice::create([
            'user_id' => $referrer->id, 'device_hash' => hash('sha256', 'referrer-device'), 'device_name' => 'Chrome',
            'device_type' => 'desktop', 'user_agent' => self::REFERRER_AGENT, 'ip_address' => $ip, 'status' => 'active', 'last_active_at' => now(),
        ]);

        return $referrer;
    }

    public function test_signup_from_the_referrer_network_and_browser_is_held_and_never_auto_rewarded(): void
    {
        $referrer = $this->referrerWithDevice('203.0.113.10');

        $this->withSession([ReferralService::SESSION_KEY => $referrer->referral_code])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => self::REFERRER_AGENT])
            ->postJson('/register', ['name' => 'Twin', 'email' => 'twin@example.com', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!'])
            ->assertCreated();

        $referral = Referral::sole();
        $this->assertSame('flagged', $referral->status);
        $this->assertSame('high', $referral->risk_level);
        $this->assertEqualsCanonicalizing(['shared_ip', 'shared_device'], $referral->risk_reasons);
        $this->assertSame('203.0.113.10', $referral->referred_ip);
        $this->assertSame(1, AuditEvent::where('action', 'referral.flagged')->count());

        $order = DurationOrder::create(['user_id' => $referral->referred_id, 'package' => '1_week', 'days' => 7, 'price' => 20000, 'status' => 'approved', 'approved_at' => now()]);
        $this->assertFalse(app(ReferralService::class)->rewardFirstPurchase($order));
        $this->assertDatabaseCount('referral_rewards', 0);
        $this->assertSame('flagged', $referral->fresh()->status);

        $member = User::query()->find($referral->referred_id);
        $this->flushSession();
        $this->actingAs($referrer)->getJson('/api/referrals/me')
            ->assertOk()->assertJsonPath('stats.under_review', 1)->assertJsonPath('recent_referrals.0.status', 'flagged')
            ->assertJsonMissing(['referred_ip' => '203.0.113.10']);
        $this->flushSession();
        $this->actingAs($member)->getJson('/api/admin/referrals/review')->assertForbidden();
    }

    public function test_clean_signup_is_attributed_but_a_later_shared_address_holds_the_reward_until_admin_release(): void
    {
        $referrer = $this->referrerWithDevice('203.0.113.10');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withSession([ReferralService::SESSION_KEY => $referrer->referral_code])
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone) Safari/604.1'])
            ->postJson('/register', ['name' => 'Friend', 'email' => 'friend@example.com', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!'])
            ->assertCreated();
        $referral = Referral::sole();
        $this->assertSame('attributed', $referral->status);
        $this->assertSame('clear', $referral->risk_level);

        // The referred account is later used from the referrer's own address.
        UserDevice::create([
            'user_id' => $referral->referred_id, 'device_hash' => hash('sha256', 'friend-second-device'), 'device_name' => 'Chrome',
            'device_type' => 'desktop', 'user_agent' => 'Mozilla/5.0 (X11; Linux) Firefox/140.0', 'ip_address' => '203.0.113.10', 'status' => 'active', 'last_active_at' => now(),
        ]);
        $order = DurationOrder::create(['user_id' => $referral->referred_id, 'package' => '1_week', 'days' => 7, 'price' => 20000, 'status' => 'approved', 'approved_at' => now(), 'approved_by' => $admin->id]);

        $this->assertFalse(app(ReferralService::class)->rewardFirstPurchase($order));
        $this->assertSame('flagged', $referral->fresh()->status);
        $this->assertSame(['shared_ip'], $referral->fresh()->risk_reasons);
        $this->assertDatabaseCount('referral_rewards', 0);

        $this->flushSession();
        $review = $this->actingAs($admin)->getJson('/api/admin/referrals/review')->assertOk();
        $this->assertSame(1, $review->json('flagged_count'));
        $this->assertSame('198.51.100.7', $review->json('referrals.0.referred_ip'));
        $this->assertSame(['shared_ip'], $review->json('referrals.0.risk_reasons'));

        $this->actingAs($admin)->postJson("/api/admin/referrals/{$referral->id}/review", ['decision' => 'approve', 'note' => 'Same office network, verified by phone'])
            ->assertOk()->assertJsonPath('referral.status', 'qualified');
        $this->assertDatabaseCount('referral_rewards', 2);
        $this->assertSame($admin->id, $referral->fresh()->reviewed_by);
        $this->assertSame(1, AuditEvent::where('action', 'referral.released')->count());
        $this->assertSame(1, AuditEvent::where('action', 'referral.rewarded')->count());

        $this->actingAs($admin)->postJson("/api/admin/referrals/{$referral->id}/review", ['decision' => 'reject'])
            ->assertUnprocessable();
    }
}
