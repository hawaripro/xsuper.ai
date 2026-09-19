<?php

namespace Tests\Feature;

use App\Mail\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailOtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function registerUnverified(): User
    {
        Mail::fake();
        $this->postJson('/register', [
            'name' => 'Fresh Member', 'email' => 'fresh@gmail.com',
            'password' => 'Secret123!', 'password_confirmation' => 'Secret123!',
        ])->assertCreated();
        Mail::assertSent(EmailOtp::class);

        return User::where('email', 'fresh@gmail.com')->sole();
    }

    public function test_registration_sends_an_otp_and_the_account_starts_unverified(): void
    {
        $user = $this->registerUnverified();

        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->email_otp_hash);
        $this->assertFalse($this->getJson('/api/user')->json('email_verified'));
    }

    public function test_unverified_members_are_locked_to_profile_endpoints_only(): void
    {
        $user = $this->registerUnverified();

        // Blocked everywhere else with a machine-readable reason.
        $this->actingAs($user)->getJson('/api/dashboard')->assertForbidden()->assertJsonPath('email_unverified', true);
        $this->actingAs($user)->getJson('/api/library')->assertForbidden();
        $this->actingAs($user)->getJson('/api/c/am')->assertForbidden();

        // Profile management and activation stay reachable.
        $this->actingAs($user)->getJson('/api/u/me')->assertOk();
        $this->actingAs($user)->postJson('/api/u/verify-email', ['code' => '000000'])->assertUnprocessable();
    }

    public function test_the_correct_code_unlocks_the_account_and_wrong_codes_burn_attempts(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email_verified_at' => null]);
        app(\App\Services\EmailOtpService::class)->send($user);

        $sent = Mail::sent(EmailOtp::class)->first();
        $code = $sent->code;

        $this->actingAs($user)->postJson('/api/u/verify-email', ['code' => '999999'])
            ->assertUnprocessable();
        $this->assertSame(1, $user->fresh()->email_otp_attempts);

        $this->actingAs($user)->postJson('/api/u/verify-email', ['code' => $code])
            ->assertOk()->assertJsonPath('verified', true);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNull($user->fresh()->email_otp_hash);

        // The gate lifts immediately.
        $this->actingAs($user->fresh())->getJson('/api/dashboard')->assertOk();
    }

    public function test_resend_respects_the_cooldown_and_admins_are_never_gated(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)->postJson('/api/u/verify-email/send')->assertOk();
        $this->actingAs($user)->postJson('/api/u/verify-email/send')->assertUnprocessable();

        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => null]);
        $this->actingAs($admin)->getJson('/api/dashboard')->assertOk();
    }
}
