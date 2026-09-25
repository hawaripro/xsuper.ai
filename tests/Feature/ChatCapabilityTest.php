<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\UsageRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The chat header and Tools panel show the provider state the server reports. A "last check
 * succeeded" claim needs a recorded check; configuration alone is reported as unverified.
 */
class ChatCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_a_healthy_provider_is_reported_configured_only_with_a_recorded_check(): void
    {
        $provider = $this->provider(['status' => 'healthy', 'last_checked_at' => now()->subMinutes(5)]);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/c/capabilities?model=capability-chat')->assertOk()
            ->assertJsonPath('provider_status.state', 'configured')
            ->assertJsonPath('provider_status.last_checked_at', $provider->fresh()->last_checked_at->toISOString());

        // A healthy flag without any recorded check (seeded or migrated data) claims nothing it cannot show.
        $provider->forceFill(['last_checked_at' => null])->save();
        $unchecked = $this->getJson('/api/c/capabilities?model=capability-chat')->assertOk()
            ->assertJsonPath('provider_status.state', 'unverified')
            ->assertJsonPath('provider_status.last_checked_at', null);
        $this->assertStringNotContainsString('check succeeded', (string) $unchecked->json('provider_status.reason'));
    }

    public function test_tools_without_an_executor_are_reported_unavailable_with_a_reason(): void
    {
        $this->provider(['status' => 'healthy', 'last_checked_at' => now()]);
        $member = User::factory()->create(['permissions' => ['chat' => true]]);

        $response = $this->actingAs($member)->getJson('/api/c/capabilities?model=capability-chat')->assertOk()
            ->assertJsonPath('tools.web_search.available', false)
            ->assertJsonPath('tools.code_interpreter.available', false)
            ->assertJsonPath('artifact.ai_revision', false);
        $this->assertNotSame('', (string) $response->json('tools.web_search.reason'));
        $this->assertNotSame('', (string) $response->json('tools.code_interpreter.reason'));
    }

    private function provider(array $state): AiProviderProfile
    {
        $provider = AiProviderProfile::create([
            'slug' => 'capability-chat', 'name' => 'Chat', 'protocol' => 'openai',
            'base_url' => 'https://chat.example.test/v1', 'api_key' => 'test-only-key', 'is_enabled' => true, ...$state,
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'capability-chat', 'upstream_model_id' => 'private-chat',
            'display_name' => 'Chat', 'category' => 'chat', 'input_modalities' => ['text'],
            'output_modalities' => ['text'], 'is_enabled' => true, 'is_available' => true,
        ]);
        foreach (['input_tokens' => 1, 'output_tokens' => 2] as $meter => $price) {
            UsageRate::create([
                'service' => 'api', 'model' => 'capability-chat', 'meter' => $meter, 'label' => $meter,
                'unit' => '1M tokens', 'price_usd' => $price, 'price_idr' => 16000 * $price, 'is_active' => true,
            ]);
        }

        return $provider;
    }
}
