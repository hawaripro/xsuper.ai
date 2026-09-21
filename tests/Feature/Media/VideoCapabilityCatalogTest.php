<?php

namespace Tests\Feature\Media;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use App\Services\FalProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The video catalog carries the resolved capability contract the studio renders from. Fal
 * video exposes text_to_video + image_to_video (a required reference asset, no aspect ratio);
 * Kinovi video is text-to-video only (it rejects reference images), so image_to_video must not
 * be offered. audio_to_video has no provider and is never derived.
 */
class VideoCapabilityCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function falModel(): AiModelProfile
    {
        $provider = AiProviderProfile::create([
            'slug' => 'fal', 'name' => 'fal', 'protocol' => 'fal',
            'base_url' => 'https://fal.run', 'api_key' => 'k', 'is_enabled' => true,
        ]);

        return AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => FalProtocol::VIDEO, 'upstream_model_id' => FalProtocol::VIDEO,
            'display_name' => 'LongCat', 'category' => 'video', 'is_enabled' => true, 'is_available' => true, 'token_cost' => 200,
        ]);
    }

    private function kinoviModel(): AiModelProfile
    {
        $provider = AiProviderProfile::create([
            'slug' => 'kinovi-ai', 'name' => 'Kinovi', 'protocol' => 'kinovi',
            'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'k', 'is_enabled' => true,
        ]);

        return AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'kinovi-ai/seedance2-5', 'upstream_model_id' => 'seedance2-5',
            'display_name' => 'Seedance', 'category' => 'video', 'is_enabled' => true, 'is_available' => true, 'token_cost' => 50,
        ]);
    }

    private function viewer(): User
    {
        return User::factory()->create(['is_active' => true, 'permissions' => ['video_generator' => true]]);
    }

    public function test_models_endpoint_exposes_text_to_video_definition_and_price(): void
    {
        $this->falModel();

        $cap = $this->actingAs($this->viewer())
            ->getJson('/api/v/models')->assertOk()
            ->json('models.0.capabilities.text_to_video');

        $this->assertIsArray($cap);
        $this->assertSame('text_to_video', $cap['operation']);
        $this->assertSame('video', $cap['output_kind']);
        $this->assertSame(200, $cap['price_tokens']);
        $this->assertIsString($cap['source_hash']);
        $this->assertNotSame('', $cap['source_hash']);

        $prompt = collect($cap['inputs'])->firstWhere('key', 'prompt');
        $this->assertNotNull($prompt);
        $this->assertTrue($prompt['required']);

        $aspect = collect($cap['params'])->firstWhere('name', 'aspect_ratio');
        $this->assertNotNull($aspect, 'text-to-video exposes aspect_ratio');
        $this->assertNotEmpty($aspect['options']);
        $this->assertNotNull(collect($cap['params'])->firstWhere('name', 'duration'), 'text-to-video exposes duration');

        $this->assertSame('textarea', $cap['ui']['inputs']['prompt']['control']);
    }

    public function test_fal_model_exposes_image_to_video_with_required_reference_and_no_aspect_ratio(): void
    {
        $this->falModel();

        $caps = $this->actingAs($this->viewer())
            ->getJson('/api/v/models')->assertOk()
            ->json('models.0.capabilities');

        $this->assertArrayHasKey('text_to_video', $caps, 'text-to-video stays available (no regression)');
        $this->assertArrayHasKey('image_to_video', $caps);

        $i2v = $caps['image_to_video'];
        $this->assertSame('image_to_video', $i2v['operation']);
        $this->assertSame('video', $i2v['output_kind']);

        $ref = collect($i2v['inputs'])->firstWhere('key', 'reference_image');
        $this->assertNotNull($ref);
        $this->assertTrue($ref['required']);
        $this->assertSame('asset', $ref['type']);
        $this->assertSame('image_ref', $ref['role']);
        $this->assertSame('image', $i2v['ui']['inputs']['reference_image']['control']);

        // The reference frame fixes geometry: aspect ratio is not a parameter, duration still is.
        $this->assertNull(collect($i2v['params'])->firstWhere('name', 'aspect_ratio'));
        $this->assertNotNull(collect($i2v['params'])->firstWhere('name', 'duration'));
    }

    public function test_kinovi_video_is_text_to_video_only(): void
    {
        $this->kinoviModel();

        $caps = $this->actingAs($this->viewer())
            ->getJson('/api/v/models')->assertOk()
            ->json('models.0.capabilities');

        $this->assertArrayHasKey('text_to_video', $caps);
        $this->assertArrayNotHasKey('image_to_video', $caps, 'Kinovi rejects reference images');
        $this->assertArrayNotHasKey('audio_to_video', $caps, 'no audio-to-video provider exists');
    }
}
