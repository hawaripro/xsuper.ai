<?php

namespace Tests\Feature\Media;

use App\Media\CapabilityResolver;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\OutputKind;
use App\Media\Exceptions\CapabilityConfigException;
use App\Media\MediaCapability;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaCapabilityRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapabilityResolverTest extends TestCase
{
    use RefreshDatabase;

    private function kinoviImageModel(): AiModelProfile
    {
        $provider = AiProviderProfile::create([
            'slug' => 'kinovi-ai', 'name' => 'Kinovi', 'protocol' => 'kinovi',
            'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'k', 'is_enabled' => true,
        ]);

        return AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'kinovi-ai/gpt-image-2',
            'upstream_model_id' => 'gpt-image-2', 'display_name' => 'GPT Image 2', 'category' => 'image',
            'is_enabled' => true, 'is_available' => true, 'token_cost' => 10,
        ]);
    }

    public function test_legacy_derivation_yields_text_to_image_capability(): void
    {
        $model = $this->kinoviImageModel();
        $resolved = app(CapabilityResolver::class)->resolve($model, MediaOperation::TextToImage);

        $this->assertSame('legacy', $resolved->origin);
        $this->assertNull($resolved->revisionId);
        $cap = $resolved->capability;
        $this->assertSame(MediaOperation::TextToImage, $cap->operation);
        $this->assertTrue($cap->input(InputRole::Prompt)->required);
        $this->assertNotNull($cap->param('size'));
        $this->assertContains('1024x1024', $cap->param('size')->options);
    }

    public function test_published_revision_overrides_derivation(): void
    {
        $model = $this->kinoviImageModel();
        $definition = [
            'model_public_id' => $model->model_id, 'operation' => 'text_to_image', 'output_kind' => 'image',
            'contract_version' => 1,
            'inputs' => [['key' => 'prompt', 'role' => 'prompt', 'type' => 'string', 'single' => true, 'max' => 1, 'required' => true]],
            'params' => [['name' => 'size', 'type' => 'enum', 'default' => '2048x2048', 'options' => ['2048x2048']]],
        ];
        MediaCapabilityRevision::create([
            'ai_model_profile_id' => $model->id, 'operation' => 'text_to_image', 'contract_version' => 1,
            'revision' => 1, 'status' => 'published', 'definition' => $definition, 'source_hash' => 'abc',
        ]);

        $resolved = app(CapabilityResolver::class)->resolve($model, MediaOperation::TextToImage);
        $this->assertSame('published', $resolved->origin);
        $this->assertSame('2048x2048', $resolved->capability->param('size')->default);
    }

    public function test_broken_published_revision_throws_not_silent_fallback(): void
    {
        $model = $this->kinoviImageModel();
        MediaCapabilityRevision::create([
            'ai_model_profile_id' => $model->id, 'operation' => 'text_to_image', 'contract_version' => 1,
            'revision' => 1, 'status' => 'published', 'definition' => ['operation' => 'not_a_real_op'], 'source_hash' => 'x',
        ]);

        $this->expectException(CapabilityConfigException::class);
        app(CapabilityResolver::class)->resolve($model, MediaOperation::TextToImage);
    }

    public function test_ensure_revision_is_find_or_create_for_legacy(): void
    {
        $model = $this->kinoviImageModel();
        $resolver = app(CapabilityResolver::class);
        $resolved = $resolver->resolve($model, MediaOperation::TextToImage);
        $rev1 = $resolver->ensureRevision($model, MediaOperation::TextToImage, $resolved);
        $rev2 = $resolver->ensureRevision($model, MediaOperation::TextToImage, $resolved);

        $this->assertSame($rev1->id, $rev2->id);
        $this->assertSame(1, MediaCapabilityRevision::where('ai_model_profile_id', $model->id)->count());
    }

    private function publishedSchemaModel(string $boundEndpoint = 'acme/studio/edit'): AiModelProfile
    {
        $provider = AiProviderProfile::create([
            'slug' => 'fal', 'name' => 'Fal', 'protocol' => 'fal',
            'base_url' => 'https://fal.run', 'api_key' => 'k', 'is_enabled' => true,
        ]);
        $model = AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'acme/studio-edit', 'upstream_model_id' => 'acme/studio/edit',
            'display_name' => 'Studio Edit', 'category' => 'image', 'is_enabled' => true, 'is_available' => true, 'token_cost' => 12,
        ]);
        $input = ['type' => 'object', 'required' => ['prompt'], 'properties' => ['prompt' => ['type' => 'string']]];
        $capability = new MediaCapability($model->model_id, MediaOperation::ImageEdit, OutputKind::Image, 2, inputSchema: $input, outputSchema: ['type' => 'object']);
        MediaCapabilityRevision::create([
            'ai_model_profile_id' => $model->id, 'operation' => 'image_edit', 'contract_version' => 2, 'revision' => 1,
            'status' => 'published', 'definition' => $capability->toArray(), 'source_hash' => 'h', 'source_schema' => ['openapi' => '3.0.4'],
            'provider_bindings' => ['adapter' => 'fal_schema_v2', 'endpoint' => $boundEndpoint, 'transport' => 'queue',
                'queue_root' => 'acme/studio', 'request_schema' => $input, 'output_schema' => ['type' => 'object'], 'constants' => []],
        ]);

        return $model;
    }

    public function test_schema_contract_is_only_resolved_for_the_unified_workspace(): void
    {
        $model = $this->publishedSchemaModel();
        $resolver = app(CapabilityResolver::class);

        $resolved = $resolver->resolve($model, MediaOperation::ImageEdit, schemaContracts: true);
        $this->assertSame(2, $resolved->capability->contractVersion);
        $this->assertSame(['prompt'], $resolved->capability->inputSchema['required']);

        // A fixed-form legacy studio must never execute (or fall back beneath) a schema contract.
        $this->expectException(CapabilityConfigException::class);
        $resolver->resolve($model, MediaOperation::ImageEdit);
    }

    public function test_schema_contract_bound_to_another_endpoint_fails_closed(): void
    {
        $model = $this->publishedSchemaModel('acme/other/model');

        $this->expectException(CapabilityConfigException::class);
        app(CapabilityResolver::class)->resolve($model, MediaOperation::ImageEdit, schemaContracts: true);
    }
}
