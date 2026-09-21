<?php

namespace Tests\Feature\Media;

use App\Exceptions\ImageGenerationException;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use App\Models\UserToken;
use App\Services\ImageGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The image models catalog must carry the resolved capability contract the frontend renders
 * from (definition + UI hints + source hash + price) so the studio never hardcodes a second
 * input ruleset, and the exposed hash round-trips through the coordinator.
 */
class ImageCapabilityCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function model(): AiModelProfile
    {
        $provider = AiProviderProfile::create([
            'slug' => 'kinovi-ai', 'name' => 'Kinovi', 'protocol' => 'kinovi',
            'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'k', 'is_enabled' => true,
        ]);

        return AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'kinovi-ai/gpt-image-2', 'upstream_model_id' => 'gpt-image-2',
            'display_name' => 'GPT Image 2', 'category' => 'image', 'is_enabled' => true, 'is_available' => true, 'token_cost' => 10,
        ]);
    }

    public function test_models_endpoint_exposes_capability_definition_ui_hash_and_price(): void
    {
        $this->model();

        $cap = $this->actingAs(User::factory()->create())
            ->getJson('/api/images/models')->assertOk()
            ->json('models.0.capabilities.text_to_image');

        $this->assertIsArray($cap);
        $this->assertSame('text_to_image', $cap['operation']);
        $this->assertSame('image', $cap['output_kind']);
        $this->assertSame(10, $cap['price_tokens']);
        $this->assertIsString($cap['source_hash']);
        $this->assertNotSame('', $cap['source_hash']);

        $prompt = collect($cap['inputs'])->firstWhere('key', 'prompt');
        $this->assertNotNull($prompt);
        $this->assertTrue($prompt['required']);

        $size = collect($cap['params'])->firstWhere('name', 'size');
        $this->assertNotNull($size, 'gpt-image-2 exposes a size param');
        $this->assertNotEmpty($size['options']);

        $this->assertNotEmpty($cap['ui']['inputs']['prompt']['label']);
        $this->assertSame('textarea', $cap['ui']['inputs']['prompt']['control']);
        $this->assertSame('select', $cap['ui']['params']['size']['control']);
    }

    public function test_exposed_source_hash_round_trips_through_generate(): void
    {
        Queue::fake();
        $this->model();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);

        $cap = $this->actingAs($user)->getJson('/api/images/models')->json('models.0.capabilities.text_to_image');

        // Submitting with the exposed hash + price must be accepted (no 409) and take the coordinator path.
        $job = app(ImageGenerationService::class)->generate(
            $user, 'kinovi-ai/gpt-image-2', 'a red apple', '1024x1024', 1,
            ['expected_capability_hash' => $cap['source_hash'], 'expected_price_tokens' => $cap['price_tokens']],
        );
        $this->assertSame('pending', $job->status);
        $this->assertNotNull($job->capability_revision_id);

        // A stale hash is rejected before any reservation.
        try {
            app(ImageGenerationService::class)->generate(
                $user, 'kinovi-ai/gpt-image-2', 'a red apple', '1024x1024', 1,
                ['expected_capability_hash' => 'stale-hash', 'expected_price_tokens' => $cap['price_tokens']],
            );
            $this->fail('expected a 409 for a stale capability hash');
        } catch (ImageGenerationException $e) {
            $this->assertSame(409, $e->responseStatus());
        }
    }
}
