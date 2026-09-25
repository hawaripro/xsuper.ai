<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberApiKeyTest extends TestCase
{
    use RefreshDatabase, ApiFixture;

    public function test_member_manages_owned_keys_with_plaintext_only_on_creation(): void
    {
        [$user] = $this->apiFixture();
        $created = $this->actingAs($user)->postJson('/api/me/api-keys', ['name' => 'My laptop'])->assertCreated();
        $id = $created->json('api_key.id');
        $plain = $created->json('key');
        $this->assertStringStartsWith('xsuper-', $plain);
        $this->assertDatabaseHas('api_keys', ['id' => $id, 'key_hash' => hash('sha256', $plain)]);
        $this->getJson('/api/me/api-keys')->assertOk()->assertDontSee($plain)->assertDontSee('key_hash');
        $this->postJson('/api/me/api-keys/'.$id.'/revoke')->assertOk();
        $this->assertFalse(ApiKey::find($id)->is_active);
        $this->deleteJson('/api/me/api-keys/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('api_keys', ['id' => $id]);
        foreach (['api_key.created', 'api_key.revoked', 'api_key.deleted'] as $action) {
            $this->assertDatabaseHas('audit_events', ['actor_id' => $user->id, 'action' => $action]);
        }
    }

    public function test_active_limit_ownership_and_permission_are_enforced(): void
    {
        [$user, $key] = $this->apiFixture();
        for ($i = 1; $i < 10; $i++) { ApiKey::generate($user->id, 'Key '.$i); }
        $this->actingAs($user)->postJson('/api/me/api-keys', ['name' => 'Eleventh'])->assertUnprocessable();
        $this->postJson('/api/me/api-keys/'.$key->id.'/revoke')->assertOk();
        $this->postJson('/api/me/api-keys', ['name' => 'Replacement'])->assertCreated();
        $other = ApiKey::generate(User::factory()->create()->id);
        $this->postJson('/api/me/api-keys/'.$other->id.'/revoke')->assertNotFound();
        $this->deleteJson('/api/me/api-keys/'.$other->id)->assertNotFound();
        $user->update(['permissions' => ['ai_api' => false]]);
        $this->getJson('/api/me/api-keys')->assertForbidden();
    }

    public function test_per_key_recent_usage_is_private_and_survives_key_deletion(): void
    {
        [$user, $key] = $this->apiFixture();
        UsageLog::record($user->id, 'public-model', ['api_key_id' => $key->id, 'cost_microusd' => 125000], 'api');
        $old = UsageLog::create(['user_id' => $user->id, 'api_key_id' => $key->id, 'model' => 'public-model', 'source' => 'api', 'cost_microusd' => 900000]);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        $response = $this->actingAs($user)->getJson('/api/me/api-keys')->assertOk();
        $response->assertJsonPath('0.usage_30d.requests', 1)->assertJsonPath('0.usage_30d.cost_usd', 0.125);
        $this->deleteJson('/api/me/api-keys/'.$key->id)->assertNoContent();
        $this->assertDatabaseHas('usage_logs', ['id' => $old->id, 'api_key_id' => null, 'cost_microusd' => 900000]);
    }
}
