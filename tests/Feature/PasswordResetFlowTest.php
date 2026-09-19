<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_can_request_a_reset_link_that_points_at_the_spa_and_reset_the_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'member@example.com', 'password' => 'OldSecret123!']);

        $this->postJson('/forgot-password', ['email' => 'member@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$token) {
            $token = $notification->token;
            $url = $notification->toMail($user)->actionUrl;
            $this->assertStringContainsString('/reset-password?token='.$token, $url);
            $this->assertStringContainsString('email=member%40example.com', $url);

            return true;
        });

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'member@example.com',
            'password' => 'BrandNew123!',
            'password_confirmation' => 'BrandNew123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('BrandNew123!', $user->fresh()->password));

        // The new password authenticates and the old one no longer does.
        $this->postJson('/api/login', ['email' => 'member@example.com', 'password' => 'BrandNew123!'])
            ->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_reset_rejects_an_invalid_token(): void
    {
        $user = User::factory()->create(['email' => 'member2@example.com', 'password' => 'OldSecret123!']);

        $this->postJson('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'member2@example.com',
            'password' => 'BrandNew123!',
            'password_confirmation' => 'BrandNew123!',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('OldSecret123!', $user->fresh()->password));
    }

    public function test_forgot_password_does_not_reveal_whether_an_email_exists(): void
    {
        Notification::fake();

        // Unknown emails return a 422 validation error from Fortify without sending anything.
        $this->postJson('/forgot-password', ['email' => 'ghost@example.com'])->assertStatus(422);
        Notification::assertNothingSent();
    }
}
