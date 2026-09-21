<?php

namespace Tests\Feature\Media;

use App\Media\Enums\InputRole;
use App\Media\FalCapabilityImporter;
use Tests\TestCase;

/**
 * F6a: prove the MediaCapability contract can represent real fal schemas and that
 * required unrepresentable fields block publication. Fixtures are derived from real fal
 * OpenAPI-3.0 responses (fixture/mock — not a live catalog import).
 */
class FalSchemaCompatibilityTest extends TestCase
{
    /** Derived from the real GET /v1/models?expand=openapi-3.0&endpoint_id=fal-ai/flux/schnell input schema. */
    private function fluxModel(): array
    {
        return [
            'endpoint_id' => 'fal-ai/flux/schnell',
            'metadata' => ['category' => 'text-to-image'],
            'openapi' => ['components' => ['schemas' => ['FluxSchnellInput' => [
                'title' => 'SchnellTextToImageInput',
                'x-fal-order-properties' => ['num_inference_steps', 'prompt', 'image_size', 'seed', 'guidance_scale', 'sync_mode', 'num_images', 'enable_safety_checker', 'output_format', 'acceleration'],
                'type' => 'object',
                'required' => ['prompt'],
                'properties' => [
                    'prompt' => ['type' => 'string', 'title' => 'Prompt'],
                    'image_size' => ['anyOf' => [['$ref' => '#/x'], ['enum' => ['square_hd', 'square', 'portrait_4_3', 'portrait_16_9', 'landscape_4_3', 'landscape_16_9'], 'type' => 'string']], 'default' => 'landscape_4_3'],
                    'seed' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
                    'guidance_scale' => ['type' => 'number', 'minimum' => 1, 'maximum' => 20, 'default' => 3.5],
                    'num_inference_steps' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'default' => 4],
                    'num_images' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'default' => 1],
                    'output_format' => ['enum' => ['jpeg', 'png'], 'type' => 'string', 'default' => 'jpeg'],
                    'enable_safety_checker' => ['type' => 'boolean', 'default' => true],
                    'sync_mode' => ['type' => 'boolean', 'default' => false],
                    'acceleration' => ['enum' => ['none', 'regular', 'high'], 'type' => 'string', 'default' => 'none'],
                ],
            ]]]],
        ];
    }

    public function test_normalizes_real_flux_image_schema_without_blockers(): void
    {
        $result = app(FalCapabilityImporter::class)->normalize($this->fluxModel());

        $this->assertTrue($result['publishable']);
        $this->assertSame('text_to_image', $result['operation']);
        $cap = $result['capability'];
        $this->assertTrue($cap->input(InputRole::Prompt)->required);
        $this->assertContains('landscape_4_3', $cap->param('image_size')->options);
        $this->assertSame(['jpeg', 'png'], $cap->param('output_format')->options);
        $this->assertSame(4, $cap->param('num_images')->max);
        $this->assertSame('number', $cap->param('guidance_scale')->type);
        // No false blockers on a clean schema.
        $this->assertSame([], array_filter($result['limitations'], fn (string $l): bool => str_contains($l, 'blocks publication')));
    }

    public function test_maps_asset_field_to_reference_input_role(): void
    {
        $model = [
            'endpoint_id' => 'fal-ai/some/image-to-video',
            'metadata' => ['category' => 'image-to-video'],
            'openapi' => ['components' => ['schemas' => ['I2VInput' => [
                'type' => 'object', 'required' => ['prompt', 'image_url'],
                'x-fal-order-properties' => ['prompt', 'image_url'],
                'properties' => [
                    'prompt' => ['type' => 'string'],
                    'image_url' => ['type' => 'string', 'title' => 'Image URL'],
                ],
            ]]]],
        ];

        $cap = app(FalCapabilityImporter::class)->normalize($model)['capability'];
        $ref = $cap->input(InputRole::ImageRef);
        $this->assertNotNull($ref);
        $this->assertSame('image_url', $ref->key);
        $this->assertTrue($ref->required);
        $this->assertSame('asset', $ref->type);
    }

    public function test_required_unrepresentable_field_blocks_publication(): void
    {
        $model = [
            'endpoint_id' => 'fal-ai/some/complex',
            'metadata' => ['category' => 'text-to-image'],
            'openapi' => ['components' => ['schemas' => ['ComplexInput' => [
                'type' => 'object', 'required' => ['prompt', 'control'],
                'x-fal-order-properties' => ['prompt', 'control'],
                'properties' => [
                    'prompt' => ['type' => 'string'],
                    'control' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]],
                ],
            ]]]],
        ];

        $result = app(FalCapabilityImporter::class)->normalize($model);
        $this->assertFalse($result['publishable']);
        $this->assertNotSame([], array_filter($result['limitations'], fn (string $l): bool => str_contains($l, 'blocks publication')));
    }

    public function test_unsupported_category_is_not_publishable(): void
    {
        $result = app(FalCapabilityImporter::class)->normalize([
            'endpoint_id' => 'fal-ai/some/3d', 'metadata' => ['category' => 'text-to-3d'], 'openapi' => ['components' => ['schemas' => []]],
        ]);
        $this->assertFalse($result['publishable']);
        $this->assertNull($result['capability']);
    }
}
