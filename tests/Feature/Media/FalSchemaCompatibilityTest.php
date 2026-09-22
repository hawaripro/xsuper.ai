<?php

namespace Tests\Feature\Media;

use App\Media\Enums\InputRole;
use App\Media\FalCapabilityImporter;
use Tests\Fixtures\FalCatalogFixture;
use Tests\TestCase;

class FalSchemaCompatibilityTest extends TestCase
{
    public function test_supported_image_schema_maps_internal_keys_without_exposing_provider_bindings(): void
    {
        $model = FalCatalogFixture::model();
        $result = app(FalCapabilityImporter::class)->normalize($model);
        $this->assertTrue($result['publishable']);
        $this->assertTrue($result['capability']->input(InputRole::Prompt)->required);
        $this->assertSame(['square', 'portrait'], $result['capability']->param('size')->options);
        $this->assertSame('image_size', $result['provider_bindings']['params']['size']);
        $this->assertArrayNotHasKey('provider_bindings', $result['capability']->toArray());
    }

    public function test_order_hints_cannot_hide_unknown_required_fields(): void
    {
        $model = FalCatalogFixture::model();
        $model['openapi']['components']['schemas']['Input']['required'][] = 'control';
        $model['openapi']['components']['schemas']['Input']['properties']['control'] = ['type' => 'object'];
        $result = app(FalCapabilityImporter::class)->normalize($model);
        $this->assertFalse($result['publishable']);
        $this->assertStringContainsString('control', implode(' ', $result['report']['blockers']));
    }

    public function test_missing_required_definition_and_unsupported_output_constraints_block_publication(): void
    {
        $model = FalCatalogFixture::model();
        $model['openapi']['components']['schemas']['Input']['required'][] = 'missing';
        $model['openapi']['components']['schemas']['Output']['properties']['images']['oneOf'] = [['type' => 'string']];
        $result = app(FalCapabilityImporter::class)->normalize($model);
        $this->assertFalse($result['publishable']);
        $this->assertStringContainsString('required input', implode(' ', $result['report']['blockers']));
        $this->assertStringContainsString('oneOf', implode(' ', $result['report']['blockers']));
    }

    public function test_reference_input_uses_internal_asset_key_and_preserves_array_binding(): void
    {
        $model = FalCatalogFixture::model();
        $model['metadata']['category'] = 'image-to-image';
        $model['openapi']['components']['schemas']['Input']['required'][] = 'image_urls';
        $model['openapi']['components']['schemas']['Input']['properties']['image_urls'] = ['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'items' => ['type' => 'string']];
        $result = app(FalCapabilityImporter::class)->normalize($model);
        $this->assertTrue($result['publishable']);
        $this->assertTrue($result['capability']->input(InputRole::ImageRef)->required);
        $this->assertSame('reference_image', $result['capability']->input(InputRole::ImageRef)->key);
        $this->assertTrue($result['provider_bindings']['reference_array']);
    }

    public function test_non_image_operation_does_not_become_runnable_by_importing_metadata(): void
    {
        $model = FalCatalogFixture::model();
        $model['metadata']['category'] = 'text-to-video';
        $this->assertFalse(app(FalCapabilityImporter::class)->normalize($model)['publishable']);
    }
}
