<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function enrol(User $user): string
    {
        $this->actingAs($user)->postJson('/api/u/security/two-factor', ['password' => 'Secret123!'])
            ->assertCreated()
            ->assertJsonPath('two_factor.pending', true)
            ->assertJsonPath('two_factor.enabled', false);

        $secret = decrypt($user->fresh()->two_factor_secret);

        $confirmed = $this->actingAs($user)->postJson('/api/u/security/two-factor/confirm', [
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ])->assertOk()->assertJsonPath('two_factor.enabled', true);

        $this->assertCount(8, $confirmed->json('recovery_codes'));

        return $secret;
    }

    public function test_enrolment_requires_the_current_password_and_a_valid_code(): void
    {
        $user = User::factory()->create(['password' => 'Secret123!']);

        $this->actingAs($user)->postJson('/api/u/security/two-factor', ['password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
        $this->assertNull($user->fresh()->two_factor_secret);

        $this->actingAs($user)->postJson('/api/u/security/two-factor', ['password' => 'Secret123!'])->assertCreated();
        $this->actingAs($user)->postJson('/api/u/security/two-factor/confirm', ['code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        // Unconfirmed enrolment never gates login.
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Secret123!'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_login_pauses_for_a_code_and_completes_only_with_a_valid_one(): void
    {
        $user = User::factory()->create(['password' => 'Secret123!']);
        $secret = $this->enrol($user);

        $this->postJson('/api/logout');
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Secret123!'])
            ->assertOk()
            ->assertExactJson(['two_factor' => true]);
        $this->assertGuest();

        $this->postJson('/api/login/two-factor', ['code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        $this->assertGuest();

        // The enrolment code was consumed; Fortify rejects replays, so use the next time step inside the verification window.
        $google2fa = new Google2FA;
        $this->postJson('/api/login/two-factor', ['code' => $google2fa->oathTotp($secret, $google2fa->getTimestamp() + 1)])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('login.id'));
    }

    public function test_a_recovery_code_works_once_and_wrong_password_never_unlocks_the_challenge(): void
    {
        $user = User::factory()->create(['password' => 'Secret123!']);
        $this->enrol($user);
        $code = $user->fresh()->recoveryCodes()[0];

        $this->postJson('/api/logout');
        $this->app['auth']->forgetGuards();

        // Wrong password never establishes a challenge session.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'nope'])->assertUnprocessable();
        $this->postJson('/api/login/two-factor', ['recovery_code' => $code])->assertStatus(419);
        $this->assertGuest();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Secret123!'])->assertJsonPath('two_factor', true);
        $this->postJson('/api/login/two-factor', ['recovery_code' => $code])->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($code, $user->fresh()->recoveryCodes());
        // Fortify swaps a used recovery code for a fresh one, so the set stays at eight distinct codes.
        $this->assertSame(8, $this->getJson('/api/u/security')->json('two_factor.recovery_codes_remaining'));

        $this->postJson('/api/logout');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Secret123!'])->assertJsonPath('two_factor', true);
        $this->postJson('/api/login/two-factor', ['recovery_code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_disabling_requires_the_password_and_restores_plain_login(): void
    {
        $user = User::factory()->create(['password' => 'Secret123!']);
        $this->enrol($user);

        $this->actingAs($user)->deleteJson('/api/u/security/two-factor', ['password' => 'wrong'])
            ->assertUnprocessable();
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());

        $this->actingAs($user)->deleteJson('/api/u/security/two-factor', ['password' => 'Secret123!'])
            ->assertOk()
            ->assertJsonPath('two_factor.enabled', false);
        $this->assertNull($user->fresh()->two_factor_secret);

        $this->postJson('/api/logout');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Secret123!'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }
}
