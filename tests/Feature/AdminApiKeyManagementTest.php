<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApiKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_toggle_regenerate_and_delete_keys(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['role' => 'member']);

        // Create
        $create = $this->actingAs($admin)->postJson('/api/k/create', [
            'user_id' => $member->id,
            'name' => 'Integration Key',
            'rate_limit' => 120,
        ]);
        $create->assertCreated();
        $key = $create->json('key');
        $this->assertStringStartsWith('ultrai-', $key);
        $row = ApiKey::where('user_id', $member->id)->firstOrFail();
        $this->assertTrue($row->is_active);
        $this->assertSame(120, $row->rate_limit);

        // List (admin sees the full key)
        $list = $this->actingAs($admin)->getJson('/api/k/list?user_id='.$member->id);
        $list->assertOk();
        $this->assertSame($key, $list->json('keys.0.key'));

        // Toggle deactivates
        $this->actingAs($admin)->postJson('/api/k/toggle/'.$row->id)->assertOk()->assertJson(['is_active' => false]);
        $this->assertFalse($row->fresh()->is_active);

        // Toggle again reactivates
        $this->actingAs($admin)->postJson('/api/k/toggle/'.$row->id)->assertOk()->assertJson(['is_active' => true]);

        // Regenerate produces a new key value
        $regen = $this->actingAs($admin)->postJson('/api/k/regen/'.$row->id);
        $regen->assertOk();
        $this->assertNotSame($key, $regen->json('key'));
        $this->assertStringStartsWith('ultrai-', $regen->json('key'));

        // Delete
        $this->actingAs($admin)->deleteJson('/api/k/'.$row->id)->assertOk();
        $this->assertDatabaseMissing('api_keys', ['id' => $row->id]);
    }

    public function test_member_cannot_access_api_key_management(): void
    {
        $member = User::factory()->create(['role' => 'member']);
        $target = User::factory()->create(['role' => 'member']);

        $this->actingAs($member)->getJson('/api/k/list')->assertForbidden();
        $this->actingAs($member)->postJson('/api/k/create', ['user_id' => $target->id])->assertForbidden();
    }
}
