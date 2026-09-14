<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DurationOrder;
use App\Models\Notification;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use LogicException;
use Mockery;
use Tests\TestCase;

class ReferralProgramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'referrals.enabled' => true,
            'referrals.reward_days' => 3,
            'referrals.cookie_name' => 'ultrai_referral',
            'referrals.cookie_minutes' => 43200,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_users_receive_unique_stable_immutable_random_referral_codes(): void
    {
        $first = User::factory()->create(['name' => 'Same Person']);
        $second = User::factory()->create(['name' => 'Same Person']);
        $code = $first->referral_code;

        $this->assertMatchesRegularExpression('/^UTR-[A-Z0-9]{12}$/', $code);
        $this->assertNotSame($code, $second->referral_code);

        $first->update(['name' => 'Renamed Person']);
        $this->assertSame($code, $first->fresh()->referral_code);

        try {
            $first->forceFill(['referral_code' => 'UTR-AAAAAAAAAAAA'])->save();
            $this->fail('Changing an issued referral code should fail.');
        } catch (LogicException) {
            $this->assertSame($code, $first->fresh()->referral_code);
        }
    }

    public function test_capture_and_registration_attribute_by_code_without_trusting_user_ids(): void
    {
        $referrer = User::factory()->create();
        $spoofedUser = User::factory()->create();

        $this->postJson('/api/referrals/capture', [
            'code' => strtolower($referrer->referral_code),
            'referrer_id' => $spoofedUser->id,
        ])->assertOk()
            ->assertJsonPath('captured', true)
            ->assertJsonMissing(['referrer_id' => $referrer->id])
            ->assertSessionHas(ReferralService::SESSION_KEY, $referrer->referral_code)
            ->assertCookie(config('referrals.cookie_name'), $referrer->referral_code);

        $this->withSession([ReferralService::SESSION_KEY => $referrer->referral_code])
            ->postJson('/register', [
                'name' => 'New Member',
                'email' => 'new-member@example.com',
                'password' => 'Secret123!',
                'password_confirmation' => 'Secret123!',
            ])->assertCreated();

        $newMember = User::where('email', 'new-member@example.com')->firstOrFail();

        $this->assertDatabaseHas('referrals', [
            'referrer_id' => $referrer->id,
            'referred_id' => $newMember->id,
            'code' => $referrer->referral_code,
            'status' => 'attributed',
        ]);
        $this->assertDatabaseMissing('referrals', [
            'referrer_id' => $spoofedUser->id,
            'referred_id' => $newMember->id,
        ]);
    }

    public function test_invalid_self_and_changed_referrers_are_rejected_without_reassignment(): void
    {
        $originalReferrer = User::factory()->create();
        $otherReferrer = User::factory()->create();
        $referred = User::factory()->create();

        $this->postJson('/api/referrals/capture', ['code' => 'UTR-NOT-A-USER'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->actingAs($referred)
            ->postJson('/api/referrals/capture', ['code' => $referred->referral_code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        Referral::create([
            'referrer_id' => $originalReferrer->id,
            'referred_id' => $referred->id,
            'code' => $originalReferrer->referral_code,
            'status' => 'attributed',
            'attributed_at' => now(),
        ]);

        $this->actingAs($referred)
            ->postJson('/api/referrals/capture', ['code' => $otherReferrer->referral_code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertSame(1, Referral::where('referred_id', $referred->id)->count());
        $this->assertSame($originalReferrer->id, $referred->fresh()->referralAttribution->referrer_id);
    }

    public function test_google_callback_attributes_from_cookie_once_without_creating_duplicates(): void
    {
        $referrer = User::factory()->create();
        $member = User::factory()->create([
            'email' => 'google-member@example.com',
            'google_id' => null,
        ]);
        $googleUser = (new SocialiteUser)->map([
            'id' => 'google-user-123',
            'name' => 'Google Member',
            'email' => $member->email,
        ]);
        $provider = Mockery::mock();

        Socialite::shouldReceive('driver')->twice()->with('google')->andReturn($provider);
        $provider->shouldReceive('user')->twice()->andReturn($googleUser);

        $this->withCookie(config('referrals.cookie_name'), $referrer->referral_code)
            ->get('/auth/google/callback')
            ->assertRedirect('/dashboard');
        $this->withCookie(config('referrals.cookie_name'), $referrer->referral_code)
            ->get('/auth/google/callback')
            ->assertRedirect('/dashboard');

        $this->assertSame('google-user-123', $member->fresh()->google_id);
        $this->assertSame(1, Referral::where('referred_id', $member->id)->count());
        $this->assertSame($referrer->id, $member->fresh()->referralAttribution->referrer_id);
    }

    public function test_registration_without_referral_remains_functional(): void
    {
        $this->postJson('/register', [
            'name' => 'Direct Member',
            'email' => 'direct-member@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ])->assertCreated();

        $member = User::where('email', 'direct-member@example.com')->firstOrFail();

        $this->assertNotNull($member->referral_code);
        $this->assertDatabaseMissing('referrals', ['referred_id' => $member->id]);
    }

    public function test_only_first_approved_order_awards_configured_duration_to_both_users_once(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');
        config(['referrals.reward_days' => 5]);

        $admin = User::factory()->create(['role' => 'admin']);
        $referrer = User::factory()->create(['expires_at' => now()->addDays(10)]);
        $referred = User::factory()->create(['expires_at' => now()->subDay()]);
        $referral = Referral::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $referred->id,
            'code' => $referrer->referral_code,
            'status' => 'attributed',
            'attributed_at' => now()->subWeek(),
        ]);
        $firstOrder = DurationOrder::create([
            'user_id' => $referred->id,
            'package' => '1_week',
            'days' => 7,
            'price' => 20000,
            'status' => 'pending',
        ]);

        $this->assertFalse(app(ReferralService::class)->rewardFirstPurchase($firstOrder));
        $this->assertSame('attributed', $referral->fresh()->status);
        $this->assertDatabaseCount('referral_rewards', 0);

        $this->actingAs($admin)
            ->postJson("/api/a/period/approve/{$firstOrder->id}")
            ->assertOk()
            ->assertJsonPath('new_expires_at', now()->addDays(12)->toISOString());

        $qualifiedReferral = $referral->fresh();
        $this->assertSame('qualified', $qualifiedReferral->status);
        $this->assertNotNull($qualifiedReferral->qualified_at);
        $this->assertSame(now()->addDays(15)->toISOString(), $referrer->fresh()->expires_at->toISOString());
        $this->assertSame(now()->addDays(12)->toISOString(), $referred->fresh()->expires_at->toISOString());
        $this->assertDatabaseCount('referral_rewards', 2);
        $this->assertSame(2, ReferralReward::distinct()->count('reference'));
        $this->assertSame(10, ReferralReward::sum('days'));
        $this->assertDatabaseCount('notifications', 2);
        $this->assertEqualsCanonicalizing(
            [$referrer->id, $referred->id],
            Notification::pluck('user_id')->all(),
        );

        $audit = AuditEvent::where('action', 'referral.rewarded')->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($referral->id, $audit->subject_id);
        $this->assertSame($firstOrder->id, $audit->metadata['order_id']);
        $this->assertSame(5, $audit->metadata['reward_days']);

        $referrerExpiryAfterReward = $referrer->fresh()->expires_at;
        $referredExpiryAfterReward = $referred->fresh()->expires_at;

        $this->assertFalse(app(ReferralService::class)->rewardFirstPurchase($firstOrder->fresh()));
        $this->actingAs($admin)
            ->postJson("/api/a/period/approve/{$firstOrder->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->assertTrue($referrerExpiryAfterReward->equalTo($referrer->fresh()->expires_at));
        $this->assertTrue($referredExpiryAfterReward->equalTo($referred->fresh()->expires_at));
        $this->assertDatabaseCount('referral_rewards', 2);
        $this->assertDatabaseCount('notifications', 2);

        $this->assertSame(1, AuditEvent::where('action', 'referral.rewarded')->count());

        $secondOrder = DurationOrder::create([
            'user_id' => $referred->id,
            'package' => '1_day',
            'days' => 1,
            'price' => 5000,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/a/period/approve/{$secondOrder->id}")
            ->assertOk();

        $this->assertTrue($referrerExpiryAfterReward->equalTo($referrer->fresh()->expires_at));
        $this->assertTrue($referredExpiryAfterReward->copy()->addDay()->equalTo($referred->fresh()->expires_at));
        $this->assertDatabaseCount('referral_rewards', 2);
    }
    public function test_manual_duration_grant_does_not_consume_first_paid_referral_reward(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        Referral::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $referred->id,
            'code' => $referrer->referral_code,
            'status' => 'attributed',
            'attributed_at' => now()->subWeek(),
        ]);
        DurationOrder::create([
            'user_id' => $referred->id,
            'package' => 'manual',
            'days' => 2,
            'price' => 0,
            'status' => 'approved',
            'approved_at' => now()->subDay(),
            'approved_by' => $admin->id,
        ]);
        $paid = DurationOrder::create([
            'user_id' => $referred->id,
            'package' => '1_week',
            'days' => 7,
            'price' => 20000,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->postJson("/api/a/period/approve/{$paid->id}")->assertOk();

        $this->assertDatabaseHas('referrals', ['referred_id' => $referred->id, 'status' => 'qualified']);
        $this->assertDatabaseCount('referral_rewards', 2);
    }

    public function test_member_stats_and_admin_program_status_enforce_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $referrer = User::factory()->create();
        $firstReferred = User::factory()->create();
        $secondReferred = User::factory()->create();
        Referral::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $firstReferred->id,
            'code' => $referrer->referral_code,
            'status' => 'attributed',
            'attributed_at' => now()->subDay(),
        ]);
        $qualified = Referral::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $secondReferred->id,
            'code' => $referrer->referral_code,
            'status' => 'qualified',
            'attributed_at' => now()->subWeek(),
            'qualified_at' => now(),
        ]);
        ReferralReward::create([
            'referral_id' => $qualified->id,
            'user_id' => $referrer->id,
            'kind' => 'duration',
            'days' => 3,
            'reference' => "referral:{$qualified->id}:first-purchase:referrer",
            'awarded_at' => now(),
        ]);

        $this->getJson('/api/referrals/me')->assertUnauthorized();

        $this->actingAs($referrer)
            ->getJson('/api/referrals/me')
            ->assertOk()
            ->assertJsonPath('program.enabled', true)
            ->assertJsonPath('program.reward_days', 3)
            ->assertJsonPath('referral.code', $referrer->referral_code)
            ->assertJsonPath('stats.invited', 2)
            ->assertJsonPath('stats.attributed', 1)
            ->assertJsonPath('stats.qualified', 1)
            ->assertJsonPath('stats.days_earned', 3)
            ->assertJsonCount(2, 'recent_referrals');

        $this->actingAs($referrer)
            ->getJson('/api/admin/referrals')
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson('/api/admin/referrals')
            ->assertOk()
            ->assertJsonPath('program.status', 'active')
            ->assertJsonPath('program.reward_days', 3)
            ->assertJsonPath('totals.referrals', 2)
            ->assertJsonPath('totals.attributed', 1)
            ->assertJsonPath('totals.qualified', 1)
            ->assertJsonPath('totals.rewards', 1)
            ->assertJsonPath('totals.days_awarded', 3);

        config(['referrals.enabled' => false]);

        $this->actingAs($admin)
            ->getJson('/api/admin/referrals')
            ->assertOk()
            ->assertJsonPath('program.status', 'disabled')
            ->assertJsonPath('program.enabled', false);

        $this->actingAs($referrer)
            ->putJson('/api/admin/referrals/configuration', [
                'enabled' => false,
                'reward_days' => 7,
            ])->assertForbidden();

        $this->actingAs($admin)
            ->putJson('/api/admin/referrals/configuration', [
                'enabled' => false,
                'reward_days' => 7,
            ])->assertOk()
            ->assertJsonPath('program.status', 'disabled')
            ->assertJsonPath('program.enabled', false)
            ->assertJsonPath('program.reward_days', 7);

        $this->assertFalse(app(ReferralService::class)->enabled());
        $this->assertSame(7, app(ReferralService::class)->rewardDays());
        $this->assertDatabaseHas('referral_program_settings', [
            'id' => 1,
            'enabled' => false,
            'reward_days' => 7,
            'updated_by' => $admin->id,
        ]);
        $configurationAudit = AuditEvent::where('action', 'referral.configuration.updated')->sole();
        $this->assertSame($admin->id, $configurationAudit->actor_id);
        $this->assertSame(7, $configurationAudit->metadata['reward_days']);
    }
}
