<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelsEndpointTest extends TestCase
{
    use RefreshDatabase, ApiFixture;

    public function test_only_sellable_available_chat_models_have_public_protocol_shapes(): void
    {
        [$user, $key, $model] = $this->apiFixture();
        $model->replicate()->fill(['model_id' => 'unpriced', 'upstream_model_id' => 'private-unpriced'])->save();
        $response = $this->withToken($key->plainKey)->getJson('/v1/models')->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('object', 'list')->assertJsonPath('data.0.id', 'public-model')
            ->assertJsonPath('data.0.object', 'model')->assertJsonPath('data.0.owned_by', 'xsuper')
            ->assertJsonPath('data.0.context_length', 200000)->assertJsonPath('data.0.pricing.cache_read_usd_per_million', 0.5);
        foreach (['private-', 'upstream', 'provider', 'landed', 'margin', 'api_key'] as $private) { $response->assertDontSee($private); }
        $this->withHeader('anthropic-version', '2023-06-01')->getJson('/v1/models')->assertOk()
            ->assertJsonPath('data.0.type', 'model')->assertJsonPath('data.0.display_name', 'Coding model')
            ->assertJsonPath('has_more', false)->assertJsonPath('first_id', 'public-model')->assertJsonPath('last_id', 'public-model');
        $this->actingAs($user)->getJson('/api/me/api-models')->assertOk()->assertJsonPath('data.0.pricing.input_usd_per_million', 2);
        $model->update(['is_available' => false]);
        $this->getJson('/v1/models')->assertJsonCount(0, 'data');
    }

    public function test_unknown_unsellable_and_invalid_requests_use_client_errors(): void
    {
        [, $key] = $this->apiFixture();
        $this->withToken($key->plainKey)->postJson('/v1/chat/completions', [])->assertStatus(400)->assertJsonPath('error.type', 'invalid_request_error');
        $this->postJson('/v1/chat/completions', ['model' => 'unknown', 'messages' => [['role' => 'user', 'content' => 'Hi']]])
            ->assertStatus(400)->assertJsonPath('error.code', 'model_not_found');
        $this->postJson('/v1/messages', ['model' => 'unknown', 'messages' => [['role' => 'user', 'content' => 'Hi']]])
            ->assertStatus(400)->assertJsonPath('type', 'error')->assertJsonPath('error.type', 'invalid_request_error');
    }
}
