<?php

namespace Tests\Feature;

use App\Models\Referral;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationReferralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['referrals.enabled' => true, 'referrals.reward_days' => 3]);
    }

    public function test_a_referral_link_landing_is_remembered_then_signup_attributes_it_with_ip(): void
    {
        $referrer = User::factory()->create(['expires_at' => now()->addDays(10)]);

        // Any web landing carrying ?ref= remembers the code for this browser (session + durable cookie).
        $landing = $this->get('/login?ref='.strtolower($referrer->referral_code));
        $landing->assertOk();
        $landing->assertCookie(app(ReferralService::class)->cookieName(), $referrer->referral_code);

        // A signup moments later attributes the referrer and records the signup IP.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/register', [
                'name' => 'Referred Member',
                'email' => 'referred@example.com',
                'password' => 'Secret123!',
                'password_confirmation' => 'Secret123!',
            ])->assertCreated();

        $referral = Referral::sole();
        $this->assertSame($referrer->id, $referral->referrer_id);
        $this->assertSame($referrer->referral_code, $referral->code);
        $this->assertSame('198.51.100.7', $referral->referred_ip);
        $this->assertNotNull($referral->referred_device_hash);
        $this->assertContains($referral->status, ['attributed', 'flagged']);
    }

    public function test_an_unknown_referral_code_never_blocks_the_landing_or_creates_a_referral(): void
    {
        $this->get('/login?ref=NOPE-NOT-REAL')->assertOk();
        $this->assertNull(session(ReferralService::SESSION_KEY));

        $this->postJson('/register', [
            'name' => 'Solo Member',
            'email' => 'solo@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ])->assertCreated();

        $this->assertDatabaseCount('referrals', 0);
        $this->assertDatabaseHas('users', ['email' => 'solo@example.com', 'role' => 'member']);
    }

    public function test_a_visitor_cannot_self_refer_from_their_own_link(): void
    {
        $referrer = User::factory()->create();

        $this->actingAs($referrer)->get('/login?ref='.$referrer->referral_code)->assertOk();
        $this->assertNull(session(ReferralService::SESSION_KEY));
    }
}
