<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyApiKey;
use App\Models\ApiKey;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

    public function test_blocked_fingerprint_stays_blocked_when_session_request_headers_change(): void
    {
        $member = User::factory()->create(['role' => 'member', 'is_active' => true]);
        $this->browserDevice($member, 'Blocked browser', 'blocked');

        $this->actingAs($member)->getJson('/api/u/me', ['User-Agent' => 'Blocked browser', 'Accept-Language' => null])
            ->assertForbidden()->assertJsonPath('device_blocked', true);
        $this->get('/api/u/me', ['User-Agent' => 'Blocked browser', 'Accept-Language' => 'id'])
            ->assertForbidden()->assertJsonPath('device_blocked', true);

        $this->assertDatabaseCount('user_devices', 1);
    }

    public function test_pending_session_device_still_requires_approval_after_an_active_slot_is_freed(): void
    {
        $member = User::factory()->create(['role' => 'member', 'is_active' => true]);
        $first = $this->browserDevice($member, 'First browser');
        $this->browserDevice($member, 'Second browser');

        $this->actingAs($member)->getJson('/api/u/me', ['User-Agent' => 'Pending browser', 'Accept-Language' => 'en'])
            ->assertForbidden()->assertJsonPath('device_pending', true);
        $pending = UserDevice::where('user_id', $member->id)->where('status', 'pending')->sole();
        $first->delete();

        $this->get('/api/u/me', ['User-Agent' => 'Pending browser', 'Sec-Fetch-Mode' => 'navigate'])
            ->assertForbidden()->assertJsonPath('device_pending', true);
        $this->assertDatabaseCount('user_devices', 2);
        $this->assertSame('pending', $pending->fresh()->status);

        $pending->update(['status' => 'active']);
        $this->get('/api/u/me', ['User-Agent' => 'Pending browser'])->assertOk();
        $this->assertSame(2, UserDevice::where('user_id', $member->id)->where('status', 'active')->count());
    }

    public function test_session_device_binding_cannot_be_reused_by_another_authenticated_principal(): void
    {
        $first = User::factory()->create(['role' => 'member', 'is_active' => true]);
        $second = User::factory()->create(['role' => 'member', 'is_active' => true]);
        $this->browserDevice($second, 'First occupied slot');
        $this->browserDevice($second, 'Second occupied slot');
        $this->actingAs($first)->getJson('/api/u/me', ['User-Agent' => 'Shared browser'])->assertOk();

        $this->actingAs($second)->get('/api/u/me', ['User-Agent' => 'Shared browser'])
            ->assertForbidden()->assertJsonPath('device_pending', true);

        $this->assertSame(1, UserDevice::where('user_id', $first->id)->where('status', 'active')->count());
        $this->assertSame(2, UserDevice::where('user_id', $second->id)->where('status', 'active')->count());
        $this->assertSame(1, UserDevice::where('user_id', $second->id)->where('status', 'pending')->count());
    }

    public function test_deleted_session_device_falls_back_to_normal_device_admission(): void
    {
        $member = User::factory()->create(['role' => 'member', 'is_active' => true]);
        $this->actingAs($member)->getJson('/api/u/me', ['User-Agent' => 'Deleted browser', 'Accept-Language' => 'en'])
            ->assertOk();
        UserDevice::where('user_id', $member->id)->sole()->delete();
        $this->browserDevice($member, 'First occupied slot');
        $this->browserDevice($member, 'Second occupied slot');

        $this->get('/api/u/me', ['User-Agent' => 'Deleted browser'])
            ->assertForbidden()->assertJsonPath('device_pending', true);

        $this->assertSame(2, UserDevice::where('user_id', $member->id)->where('status', 'active')->count());
        $this->assertSame(1, UserDevice::where('user_id', $member->id)->where('status', 'pending')->count());
    }

    public function test_plugin_key_ignores_and_preserves_an_attached_browser_session_device(): void
    {
        $member = User::factory()->create([
            'role' => 'member', 'is_active' => true, 'expires_at' => now()->addDay(),
            'permissions' => ['ai_api' => true],
        ]);
        $this->actingAs($member)->getJson('/api/u/me', ['User-Agent' => 'Shared client', 'Accept-Language' => 'en'])
            ->assertOk();
        $key = ApiKey::generate($member->id, 'Device policy regression');
        $request = Request::create('/v1/models', 'GET', server: [
            'HTTP_USER_AGENT' => 'Shared client',
            'HTTP_ACCEPT_LANGUAGE' => 'en',
            'HTTP_AUTHORIZATION' => 'Bearer '.$key->plainKey,
        ]);
        $request->setLaravelSession($this->app['session.store']);
        $request->setUserResolver(fn () => $member);
        $middleware = app(VerifyApiKey::class);
        $next = fn () => response()->noContent();

        $this->assertSame(204, $middleware->handle($request, $next)->getStatusCode());
        $plugin = UserDevice::where('user_id', $member->id)->where('device_type', 'plugin')->sole();
        $this->assertSame(2, UserDevice::where('user_id', $member->id)->where('status', 'active')->count());

        $plugin->update(['status' => 'blocked']);
        $denied = $middleware->handle($request, $next);
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame('device_limit_error', $denied->getData(true)['error']['type']);
        $this->get('/api/u/me', ['User-Agent' => 'Shared client'])->assertOk();
        $this->assertDatabaseCount('user_devices', 2);
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

    public function test_admin_cannot_authenticate_from_a_disallowed_ip(): void
    {
        User::factory()->create([
            'role' => 'admin', 'email' => 'boss@example.com',
            'password' => bcrypt('Secret123!'), 'email_verified_at' => now(),
        ]);
        SecuritySetting::current()->update([
            'enforce_admin_ip' => true,
            'admin_ip_allowlist' => ['10.0.0.0/8'],
        ]);

        $this->postJson('/api/login', ['email' => 'boss@example.com', 'password' => 'Secret123!'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ip_not_allowed');

        $this->assertGuest();
    }

    public function test_member_login_is_unaffected_by_the_admin_allowlist(): void
    {
        User::factory()->create([
            'role' => 'member', 'email' => 'user@example.com',
            'password' => bcrypt('Secret123!'), 'email_verified_at' => now(),
            'is_active' => true, 'expires_at' => now()->addDays(30),
        ]);
        SecuritySetting::current()->update([
            'enforce_admin_ip' => true,
            'admin_ip_allowlist' => ['10.0.0.0/8'],
        ]);

        $this->postJson('/api/login', ['email' => 'user@example.com', 'password' => 'Secret123!'])
            ->assertOk();
    }

    public function test_allowlist_suggests_an_ipv6_prefix_instead_of_a_rotating_address(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '2a0a:4cc0:c0:d22e:a446:15ff:fe60:7ca5'])
            ->getJson('/api/security/settings')
            ->assertOk();

        $this->assertSame('2a0a:4cc0:c0:d22e::/64', $response->json('suggested_entry'));
    }

    private function browserDevice(User $member, string $userAgent, string $status = 'active'): UserDevice
    {
        return UserDevice::create([
            'user_id' => $member->id,
            'device_hash' => hash('sha256', implode('|', [$member->id, $userAgent, '', '', ''])),
            'device_name' => $userAgent,
            'device_type' => 'browser',
            'user_agent' => $userAgent,
            'status' => $status,
        ]);
    }
}
