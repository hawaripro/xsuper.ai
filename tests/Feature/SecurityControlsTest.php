<?php

namespace Tests\Feature;

use App\Models\SecuritySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_member_is_blocked_from_authenticated_surfaces(): void
    {
        $member = User::factory()->create([
            'role' => 'member', 'is_active' => false,
            'email_verified_at' => now(), 'expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($member)->getJson('/api/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_disabled');
    }

    public function test_active_member_reaches_authenticated_surfaces(): void
    {
        $member = User::factory()->create([
            'role' => 'member', 'is_active' => true,
            'email_verified_at' => now(), 'expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($member)->getJson('/api/dashboard')->assertOk();
    }

    public function test_admin_ip_allowlist_blocks_disallowed_ip_and_allows_listed_ip(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        // Enforce with a range that excludes the test client IP (127.0.0.1).
        SecuritySetting::current()->update([
            'enforce_admin_ip' => true,
            'admin_ip_allowlist' => ['10.0.0.0/8'],
        ]);

        $this->actingAs($admin)->getJson('/api/security/settings')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ip_not_allowed');

        // Widen the allowlist to include localhost — access is restored.
        SecuritySetting::current()->update(['admin_ip_allowlist' => ['127.0.0.1', '10.0.0.0/8']]);
        $this->actingAs($admin)->getJson('/api/security/settings')->assertOk();
    }

    public function test_security_settings_update_refuses_self_lockout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson('/api/security/settings', [
            'enforce_admin_ip' => true,
            'require_admin_2fa' => false,
            'admin_ip_allowlist' => ['203.0.113.5'],
        ])->assertStatus(422);

        // Including the current IP is accepted.
        $this->actingAs($admin)->putJson('/api/security/settings', [
            'enforce_admin_ip' => true,
            'require_admin_2fa' => false,
            'admin_ip_allowlist' => ['127.0.0.1'],
        ])->assertOk()->assertJsonPath('settings.enforce_admin_ip', true);
    }

    public function test_members_cannot_touch_security_settings(): void
    {
        $member = User::factory()->create([
            'role' => 'member', 'email_verified_at' => now(), 'expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($member)->getJson('/api/security/settings')->assertForbidden();
    }
}
