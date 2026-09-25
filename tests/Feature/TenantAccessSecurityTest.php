<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyApiKey;
use App\Models\ApiKey;
use App\Models\Feedback;
use App\Models\Notification;
use App\Models\SecuritySetting;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TenantAccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const FORGED_BEARER = 'Bearer xsuper-local-review-placeholder';

    public function test_forged_api_key_header_cannot_escape_a_blocked_browser_session(): void
    {
        $member = $this->member();
        $this->browserDevice($member, 'Blocked browser', 'blocked');
        $this->actingAs($member)->getJson('/api/u/me', ['User-Agent' => 'Blocked browser', 'Accept-Language' => null])
            ->assertForbidden()->assertJsonPath('device_blocked', true);

        $this->getJson('/api/u/me', ['User-Agent' => 'Blocked browser', 'Authorization' => self::FORGED_BEARER])
            ->assertForbidden()->assertJsonPath('device_blocked', true);

        $this->assertDatabaseCount('user_devices', 1);
    }

    public function test_forged_api_key_header_is_not_a_plugin_identity_for_a_fresh_session(): void
    {
        $member = $this->member();
        $this->browserDevice($member, 'Blocked browser', 'blocked');

        $this->actingAs($member)->getJson('/api/u/me', [
            'User-Agent' => 'Blocked browser', 'Accept-Language' => null, 'Authorization' => self::FORGED_BEARER,
        ])->assertForbidden()->assertJsonPath('device_blocked', true);

        $this->assertDatabaseCount('user_devices', 1);
    }

    public function test_forged_api_key_header_cannot_escape_a_pending_browser_session_after_a_slot_frees(): void
    {
        $member = $this->member();
        $first = $this->browserDevice($member, 'First browser');
        $this->browserDevice($member, 'Second browser');
        $this->actingAs($member)->getJson('/api/u/me', ['User-Agent' => 'Pending browser', 'Accept-Language' => 'en'])
            ->assertForbidden()->assertJsonPath('device_pending', true);
        $pending = UserDevice::where('user_id', $member->id)->where('status', 'pending')->sole();
        $first->delete();

        $this->getJson('/api/u/me', ['User-Agent' => 'Pending browser', 'Authorization' => self::FORGED_BEARER])
            ->assertForbidden()->assertJsonPath('device_pending', true);

        $this->assertDatabaseCount('user_devices', 2);
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_verified_api_key_does_not_rebind_or_admit_the_blocked_browser_session(): void
    {
        $member = $this->member(['expires_at' => now()->addDay(), 'permissions' => ['ai_api' => true]]);
        $this->browserDevice($member, 'Shared client', 'blocked');
        $this->actingAs($member)->getJson('/api/u/me', ['User-Agent' => 'Shared client', 'Accept-Language' => null])
            ->assertForbidden()->assertJsonPath('device_blocked', true);
        $key = ApiKey::generate($member->id, 'Tenant access regression');

        $this->getJson('/v1/models', ['User-Agent' => 'Shared client', 'Authorization' => 'Bearer '.$key->plainKey])
            ->assertOk()->assertJsonPath('object', 'list');
        $this->assertDatabaseMissing('user_devices', ['user_id' => $member->id, 'device_type' => 'plugin']);

        $request = Request::create('/v1/models', 'GET', server: [
            'HTTP_USER_AGENT' => 'Shared client',
            'HTTP_AUTHORIZATION' => 'Bearer '.$key->plainKey,
        ]);
        $request->setLaravelSession($this->app['session.store']);
        $request->setUserResolver(fn () => $member);
        $response = app(VerifyApiKey::class)->handle($request, fn () => response()->noContent());

        $this->assertSame(204, $response->getStatusCode());
        $this->getJson('/api/u/me', ['User-Agent' => 'Shared client'])
            ->assertForbidden()->assertJsonPath('device_blocked', true);
        $this->assertDatabaseCount('user_devices', 1);
    }

    public function test_every_support_alias_holds_admins_to_the_required_two_factor_policy(): void
    {
        [$owner, $ticket] = $this->memberTicket();
        $admin = User::factory()->create(['role' => 'admin']);
        SecuritySetting::current()->update(['require_admin_2fa' => true]);
        $this->actingAs($admin);

        foreach (['/api/support/tickets', '/api/admin/support/tickets'] as $alias) {
            $this->getJson("{$alias}/{$ticket->id}")
                ->assertForbidden()->assertJsonPath('code', 'admin_2fa_required')->assertJsonMissingPath('data');
            $this->postJson("{$alias}/{$ticket->id}/replies", ['body' => 'Staff reply without two-factor'])
                ->assertForbidden()->assertJsonPath('code', 'admin_2fa_required');
        }

        $this->assertSame(1, SupportMessage::where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, Notification::where('user_id', $owner->id)->count());
        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_member_support_alias_holds_admins_to_the_ip_allowlist_while_members_keep_their_tickets(): void
    {
        [$owner, $ticket] = $this->memberTicket();
        $stranger = $this->member();
        $admin = $this->twoFactorAdmin();
        SecuritySetting::current()->update([
            'enforce_admin_ip' => true, 'admin_ip_allowlist' => ['10.0.0.0/8'], 'require_admin_2fa' => true,
        ]);

        $this->actingAs($admin)->getJson("/api/support/tickets/{$ticket->id}")
            ->assertForbidden()->assertJsonPath('code', 'ip_not_allowed')->assertJsonMissingPath('data');
        $this->postJson("/api/support/tickets/{$ticket->id}/replies", ['body' => 'Staff reply from a disallowed network'])
            ->assertForbidden()->assertJsonPath('code', 'ip_not_allowed');

        $this->actingAs($owner)->getJson("/api/support/tickets/{$ticket->id}")
            ->assertOk()->assertJsonPath('data.id', $ticket->id)->assertJsonCount(1, 'data.messages');
        $this->postJson("/api/support/tickets/{$ticket->id}/replies", ['body' => 'Member follow-up'])
            ->assertCreated()->assertJsonPath('data.is_staff', false)->assertJsonPath('ticket.status', 'open');
        $this->actingAs($stranger)->getJson("/api/support/tickets/{$ticket->id}")->assertNotFound();

        $this->assertSame(0, SupportMessage::where('ticket_id', $ticket->id)->where('is_staff', true)->count());
        $this->assertSame(0, Notification::where('user_id', $owner->id)->count());
    }

    public function test_policy_compliant_admin_still_answers_through_the_member_support_alias(): void
    {
        [$owner, $ticket] = $this->memberTicket();
        $admin = $this->twoFactorAdmin();
        SecuritySetting::current()->update([
            'enforce_admin_ip' => true, 'admin_ip_allowlist' => ['127.0.0.1'], 'require_admin_2fa' => true,
        ]);

        $this->actingAs($admin)->getJson("/api/support/tickets/{$ticket->id}")
            ->assertOk()->assertJsonPath('data.user.id', $owner->id);
        $this->postJson("/api/support/tickets/{$ticket->id}/replies", ['body' => 'Compliant staff reply'])
            ->assertCreated()->assertJsonPath('data.is_staff', true)->assertJsonPath('ticket.status', 'waiting_on_member');

        $this->assertSame(1, Notification::where('user_id', $owner->id)->where('kind', 'support')->count());
    }

    public function test_member_feedback_history_hides_the_staff_note_that_admins_still_see(): void
    {
        $member = $this->member();
        $admin = User::factory()->create(['role' => 'admin']);
        $feedback = Feedback::create([
            'user_id' => $member->id, 'rating' => 4, 'category' => 'product', 'message' => 'Useful dashboard feedback.',
        ]);
        $this->actingAs($admin)->patchJson("/api/admin/feedback/{$feedback->id}", [
            'status' => 'reviewed', 'admin_note' => 'internal-review-marker',
        ])->assertOk()->assertJsonPath('data.admin_note', 'internal-review-marker');

        $history = $this->actingAs($member)->getJson('/api/feedback')
            ->assertOk()
            ->assertJsonPath('data.0.id', $feedback->id)
            ->assertJsonPath('data.0.status', 'reviewed')
            ->assertJsonPath('data.0.message', 'Useful dashboard feedback.')
            ->assertJsonMissingPath('data.0.admin_note');
        $this->assertStringNotContainsString('internal-review-marker', $history->getContent());

        $this->actingAs($admin)->getJson('/api/admin/feedback')
            ->assertOk()->assertJsonPath('data.0.admin_note', 'internal-review-marker');
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'member', 'is_active' => true, ...$attributes]);
    }

    private function twoFactorAdmin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: SupportTicket} */
    private function memberTicket(): array
    {
        $owner = $this->member();
        $ticket = SupportTicket::create([
            'user_id' => $owner->id, 'subject' => 'Private billing thread', 'category' => 'billing',
            'priority' => 'normal', 'status' => 'open', 'last_replied_at' => now(),
        ]);
        $ticket->messages()->create(['user_id' => $owner->id, 'body' => 'Member-only context.', 'is_staff' => false]);

        return [$owner, $ticket];
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
