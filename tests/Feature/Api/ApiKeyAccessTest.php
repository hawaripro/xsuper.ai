<?php

namespace Tests\Feature\Api;

use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiKeyAccessTest extends TestCase
{
    use RefreshDatabase, ApiFixture;

    public function test_api_key_headers_are_independent_of_membership_and_devices(): void
    {
        [$user, $key] = $this->apiFixture();
        foreach (['Claude Code', 'Cursor', 'Cline', 'Roo'] as $agent) {
            $this->withHeaders(['x-api-key' => $key->plainKey, 'User-Agent' => $agent])->getJson('/v1/models')->assertOk();
        }
        $this->assertSame(0, UserDevice::where('user_id', $user->id)->count());
        $this->assertSame(4, $key->fresh()->total_requests);
        RateLimiter::hit('api_rate:'.$key->id, 60);
        $key->update(['rate_limit' => 5]);
        $this->postJson('/v1/messages/count_tokens', [])->assertStatus(429)->assertJsonPath('type', 'error')->assertJsonPath('error.type', 'rate_limit_error');
    }

    public function test_unverified_disabled_and_revoked_credentials_use_endpoint_envelopes(): void
    {
        [$user, $key] = $this->apiFixture();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->withToken($key->plainKey)->postJson('/v1/messages', [])->assertForbidden()->assertJsonPath('type', 'error')->assertJsonPath('error.type', 'permission_error');
        $user->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->getJson('/v1/models')->assertForbidden()->assertJsonStructure(['error' => ['message', 'type', 'code']]);
        $user->update(['is_active' => true]);
        $key->update(['is_active' => false]);
        $this->postJson('/v1/messages', [])->assertUnauthorized()->assertJsonPath('error.type', 'authentication_error');
    }

    public function test_allowed_models_apply_to_listing_and_both_completion_families(): void
    {
        [, $key] = $this->apiFixture();
        $key->update(['allowed_models' => ['another-model']]);
        $this->withToken($key->plainKey)->getJson('/v1/models')->assertExactJson(['object' => 'list', 'data' => []]);
        foreach (['/v1/messages', '/v1/chat/completions'] as $endpoint) {
            $this->postJson($endpoint, ['model' => 'public-model', 'messages' => [['role' => 'user', 'content' => 'Hi']]])
                ->assertForbidden()->assertJsonPath('error.type', 'permission_error');
        }
    }
}
