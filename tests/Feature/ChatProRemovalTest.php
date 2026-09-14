<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatProRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_chat_pro_routes_are_not_registered(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->getJson('/api/a/chat-pro/users')->assertNotFound();
        $this->actingAs($admin)->postJson('/api/a/chat-pro/logout/legacy')->assertNotFound();
    }

    public function test_default_permissions_do_not_contain_chat_pro(): void
    {
        $this->assertArrayNotHasKey('chat_ai_pro', User::DEFAULT_PERMISSIONS);
        $this->assertArrayNotHasKey('chat_ai_pro', User::factory()->create()->getPermissions());
    }

    public function test_admin_user_crud_does_not_call_external_openwebui(): void
    {
        Http::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $created = $this->actingAs($admin)->postJson('/api/a/u', [
            'name' => 'Independent User',
            'email' => 'independent@example.com',
            'password' => 'Correct-Horse-2026!',
            'role' => 'member',
            'permissions' => User::DEFAULT_PERMISSIONS,
        ])->assertCreated();

        $this->actingAs($admin)->deleteJson('/api/a/u/'.$created->json('user.id'))->assertOk();
        Http::assertNothingSent();
    }
}
