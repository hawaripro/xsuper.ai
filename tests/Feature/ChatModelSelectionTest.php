<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatModelSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_requires_an_explicit_model(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/c/s', [
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ])->assertUnprocessable()->assertJsonValidationErrors('model');
    }

    public function test_auto_model_is_not_a_client_escape_hatch(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/c/s', [
            'model' => 'au'.'to',
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ])->assertForbidden();
    }

    public function test_chat_catalog_includes_enabled_available_profile_without_claiming_unavailable_models(): void
    {
        $user = User::factory()->create();
        $provider = AiProviderProfile::create([
            'slug' => 'ai-proxy',
            'name' => 'AI Proxy',
            'status' => 'healthy',
            'is_enabled' => true,
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id,
            'model_id' => 'available-db-model',
            'display_name' => 'Available DB Model',
            'provider_name' => 'AI Proxy',
            'category' => 'chat',
            'capabilities' => ['chat'],
            'input_modalities' => ['text'],
            'output_modalities' => ['text'],
            'is_enabled' => true,
            'is_available' => true,
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id,
            'model_id' => 'curated-db-model',
            'display_name' => 'Curated DB Model',
            'provider_name' => 'AI Proxy',
            'category' => 'chat',
            'capabilities' => ['chat'],
            'input_modalities' => ['text'],
            'output_modalities' => ['text'],
            'is_enabled' => true,
            'is_available' => false,
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id,
            'model_id' => 'disabled-db-model',
            'display_name' => 'Disabled DB Model',
            'provider_name' => 'AI Proxy',
            'category' => 'chat',
            'capabilities' => ['chat'],
            'input_modalities' => ['text'],
            'output_modalities' => ['text'],
            'is_enabled' => false,
            'is_available' => true,
        ]);
        Http::fake(['*' => Http::response(['data' => []])]);

        $response = $this->actingAs($user)->getJson('/api/c/am')->assertOk();
        $models = collect($response->json('models'));

        $this->assertTrue($models->contains(fn ($model) => $model['id'] === 'available-db-model'));
        $this->assertFalse($models->contains('id', 'curated-db-model'));
        $this->assertFalse($models->contains('id', 'disabled-db-model'));
    }
}
