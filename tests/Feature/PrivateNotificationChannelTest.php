<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivateNotificationChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_user_can_authorize_only_their_own_notification_channel(): void
    {
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'fixture-public-key');
        config()->set('broadcasting.connections.reverb.secret', 'fixture-private-signing-key');
        config()->set('broadcasting.connections.reverb.app_id', 'fixture-app');
        require base_path('routes/channels.php');
        $member = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        $payload = ['socket_id' => '123.456', 'channel_name' => 'private-App.Models.User.'.$member->id];

        $this->postJson('/api/broadcasting/auth', $payload)->assertUnauthorized();
        $this->actingAs($member)->postJson('/api/broadcasting/auth', $payload)->assertOk()->assertJsonStructure(['auth']);
        $payload['channel_name'] = 'private-App.Models.User.'.$other->id;
        $this->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
    }
}
