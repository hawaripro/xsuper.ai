<?php

namespace Tests\Feature\Media;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResponseSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_image_models_hide_provider_and_expose_operations(): void
    {
        $provider = AiProviderProfile::create([
            'slug' => 'kinovi-ai', 'name' => 'Kinovi', 'protocol' => 'kinovi',
            'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'k', 'is_enabled' => true,
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'kinovi-ai/gpt-image-2', 'upstream_model_id' => 'gpt-image-2',
            'display_name' => 'GPT Image 2', 'category' => 'image', 'is_enabled' => true, 'is_available' => true, 'token_cost' => 10,
        ]);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/images/models')->assertOk();
        $model = $response->json('models.0');

        $this->assertSame('kinovi-ai/gpt-image-2', $model['id']);
        $this->assertArrayNotHasKey('provider', $model);
        $this->assertContains('text_to_image', $model['operations']);
    }

    public function test_admin_provider_payload_exposes_sanitized_last_error(): void
    {
        $provider = AiProviderProfile::create([
            'slug' => 'ofox', 'name' => 'Ofox', 'protocol' => 'openai',
            'base_url' => 'https://ofox.test/v1', 'api_key' => 'k', 'is_enabled' => true,
            'last_error' => 'Upstream 401 with Authorization Bearer sk-live-TOPSECRET failed via https://api.x/y?signature=DEADBEEF&foo=1',
        ]);

        $payload = $provider->adminPayload();

        $this->assertArrayHasKey('last_error', $payload);
        $this->assertStringContainsString('[redacted]', $payload['last_error']);
        $this->assertStringNotContainsString('sk-live-TOPSECRET', $payload['last_error']);
        $this->assertStringNotContainsString('DEADBEEF', $payload['last_error']);
    }
}
