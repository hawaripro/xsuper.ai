<?php

namespace Tests\Unit\Media;

use App\Media\MediaJsonSchema;
use App\Media\RunwareSchemaNormalizer;
use PHPUnit\Framework\TestCase;

class RunwareSchemaNormalizerTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/runware';

    private const TRANSPORT = ['taskType', 'taskUUID', 'model', 'webhookURL', 'uploadEndpoint', 'deliveryMethod', 'outputType', 'ttl', 'includeCost'];

    private function bundle(string $id): array
    {
        $index = collect(json_decode(file_get_contents(self::FIXTURES.'/index.json'), true))->firstWhere('id', $id);
        $content = collect(json_decode(file_get_contents(self::FIXTURES.'/content-models.json'), true))->firstWhere('model', $id);
        $creator = collect(json_decode(file_get_contents(self::FIXTURES.'/creators.json'), true))->firstWhere('id', $content['creator'] ?? null);
        $schema = self::FIXTURES.'/schemas/'.$id.'.json';
        $examples = self::FIXTURES.'/examples/'.$id.'.json';

        return ['model_id' => $id, 'schema_url' => $index['schema'] ?? null, 'index' => $index, 'content' => $content, 'creator' => $creator,
            'openapi' => is_file($schema) ? json_decode(file_get_contents($schema), true) : null,
            'examples' => is_file($examples) ? json_decode(file_get_contents($examples), true) : null];
    }

    private function normalize(string $id, array $bundle = []): array
    {
        return (new RunwareSchemaNormalizer)->normalize([...$this->bundle($id), ...$bundle], 'runware/'.$id);
    }

    public function test_flux_dev_splits_text_and_seed_image_modes_into_member_contracts(): void
    {
        $model = $this->normalize('bfl-flux-1-dev');

        $this->assertSame(['text_to_image', 'image_edit'], array_keys($model['operations']));
        $this->assertSame(['runware:101@1', 'imageInference', 'image', 'FLUX.1 [dev]'], [$model['air'], $model['task_type'], $model['category'], $model['name']]);
        $text = $model['operations']['text_to_image'];
        $edit = $model['operations']['image_edit'];
        $this->assertTrue($text['publishable'] && $edit['publishable']);

        $schema = $text['capability']->inputSchema;
        $this->assertSame([], array_intersect(array_keys($schema['properties']), self::TRANSPORT));
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['positivePrompt', 'width', 'height'], $schema['required']);
        // Text-to-image sends no input files, so a seed-only control like strength would always be rejected upstream.
        $this->assertArrayNotHasKey('inputs', $schema['properties']);
        $this->assertArrayNotHasKey('strength', $schema['properties']);
        $this->assertSame(['core', 'core', 'features', 'advanced', 'advanced'], [
            $schema['properties']['positivePrompt']['x-workspace-group'], $schema['properties']['numberResults']['x-workspace-group'],
            $schema['properties']['hiresFix']['x-workspace-group'], $schema['properties']['outputFormat']['x-workspace-group'],
            $schema['properties']['advancedFeatures']['x-workspace-group'],
        ]);
        $this->assertNotEmpty(MediaJsonSchema::errors($schema, ['positivePrompt' => 'A lighthouse', 'width' => 1024, 'height' => 1024, 'strength' => 0.5]));

        $schema = $edit['capability']->inputSchema;
        $this->assertContains('inputs', $schema['required']);
        $this->assertSame(['seedImage'], $schema['properties']['inputs']['required']);
        $this->assertSame(['kind' => 'image', 'role' => 'image_ref', 'accepts_url' => true], $schema['properties']['inputs']['properties']['seedImage']['x-workspace-asset']);
        $this->assertSame('mask_image', $schema['properties']['inputs']['properties']['maskImage']['x-workspace-asset']['role']);
        $this->assertSame(0.8, $schema['properties']['strength']['default']);
        $request = ['positivePrompt' => 'Paint it', 'width' => 1024, 'height' => 1024];
        $this->assertNotEmpty(MediaJsonSchema::errors($schema, $request));
        $this->assertNotEmpty(MediaJsonSchema::errors($schema, [...$request, 'inputs' => ['maskImage' => '4b0c7d3e-2f7a-4c55-9a53-0a5f1f8e3b21']]));
        $this->assertSame([], MediaJsonSchema::errors($schema, [...$request, 'inputs' => ['seedImage' => '4b0c7d3e-2f7a-4c55-9a53-0a5f1f8e3b21']]));
    }

    public function test_result_count_is_clamped_for_members_but_the_binding_keeps_the_provider_contract(): void
    {
        $text = $this->normalize('bfl-flux-1-dev')['operations']['text_to_image'];
        $member = $text['capability']->inputSchema['properties']['numberResults'];
        $bindings = $text['provider_bindings'];

        $this->assertSame([1, 4, 1], [$member['minimum'], $member['maximum'], $member['default']]);
        $this->assertSame(20, $bindings['request_schema']['properties']['numberResults']['maximum']);
        $this->assertSame('numberResults', $bindings['quantity_input']);
        $this->assertSame(['adapter' => 'runware_v1', 'endpoint' => 'runware:101@1', 'task_type' => 'imageInference', 'transport' => 'async'],
            array_intersect_key($bindings, array_flip(['adapter', 'endpoint', 'task_type', 'transport'])));
        $this->assertSame(['deliveryMethod' => 'async', 'outputType' => 'URL', 'includeCost' => true], $bindings['constants']);
        $this->assertSame(['model_id' => 'bfl-flux-1-dev', 'schema_url' => 'https://runware.ai/docs/models/bfl-flux-1-dev/schema.json'], $bindings['source']);
        $this->assertArrayHasKey('deliveryMethod', $bindings['request_schema']['properties']);
        $this->assertNotEmpty(MediaJsonSchema::errors($text['capability']->inputSchema, ['positivePrompt' => 'x y', 'width' => 64 * 16, 'height' => 1024, 'numberResults' => 5]));
    }

    public function test_member_results_hide_provider_cost_and_annotate_downloadable_outputs(): void
    {
        $text = $this->normalize('bfl-flux-1-dev')['operations']['text_to_image'];
        $member = $text['capability']->outputSchema;

        foreach (['cost', 'taskUUID', 'taskType'] as $private) {
            $this->assertArrayNotHasKey($private, $member['properties']);
            $this->assertNotContains($private, $member['required'] ?? []);
            $this->assertArrayHasKey($private, $text['provider_bindings']['output_schema']['properties']);
        }
        $this->assertSame(['kind' => 'image'], $member['properties']['imageURL']['x-workspace-output']);
        $this->assertSame(['kind' => 'image', 'encoding' => 'base64'], $member['properties']['imageBase64Data']['x-workspace-output']);
        $this->assertSame(['kind' => 'image'], $member['properties']['imageDataURI']['x-workspace-output']);
    }

    public function test_examples_are_scalar_form_values_valid_for_their_operation(): void
    {
        $model = $this->normalize('bfl-flux-1-dev');
        $text = $model['operations']['text_to_image'];
        $edit = $model['operations']['image_edit'];

        $this->assertSame('Coastal Radar Station Rental Interior', $text['examples'][0]['title']);
        $this->assertSame(['positivePrompt', 'width', 'height', 'steps'], array_keys($text['examples'][0]['values']));
        $this->assertSame($text['examples'][0]['values']['positivePrompt'], $text['examples'][0]['prompt']);
        $this->assertSame([], MediaJsonSchema::errors($text['capability']->inputSchema, $text['examples'][0]['values']));
        $this->assertNotEmpty($edit['examples']);
        $this->assertLessThanOrEqual(6, count($edit['examples']));
        foreach ($edit['examples'] as $example) {
            // Asset values are never offered as example form values.
            $this->assertArrayNotHasKey('inputs', $example['values']);
        }
    }

    public function test_compute_time_pricing_is_an_admin_reference_with_a_flat_price_note(): void
    {
        $pricing = $this->normalize('bfl-flux-1-dev')['pricing'];

        $this->assertSame('generation', $pricing['catalog_unit']);
        $this->assertSame([], $pricing['rates']);
        $this->assertSame(['configuration' => '1024x1024 · 28 steps', 'price' => 0.0038], $pricing['measured'][0]);
        $this->assertSame(['configuration', 'price', 'latency_ms'], array_keys($pricing['examples'][0]));
        $this->assertSame('Each image generation costs $0.0038 at 1024x1024.', $pricing['overview']);
        $this->assertStringContainsString('compute time', $pricing['note']);
        $this->assertStringContainsString('2048 × 2048 px', $pricing['note']);
        $this->assertStringContainsString('steps up to 100', $pricing['note']);
    }

    public function test_video_frames_and_whole_second_duration_make_a_per_second_contract(): void
    {
        $model = $this->normalize('klingai-video-2-6-pro');

        $this->assertSame(['text_to_video', 'image_to_video', 'video_to_video'], array_keys($model['operations']));
        $this->assertSame('second', $model['pricing']['catalog_unit']);
        $this->assertSame(['durationSecond', 'durationSecond'], array_column($model['pricing']['rates'], 'unit'));
        $this->assertNull($model['pricing']['note']);

        $text = $model['operations']['text_to_video']['capability']->inputSchema;
        $this->assertSame(['type' => 'integer', 'enum' => [5, 10], 'default' => 5], array_intersect_key($text['properties']['duration'], array_flip(['type', 'enum', 'default'])));
        $this->assertContains('duration', $text['required']);

        $frames = $model['operations']['image_to_video'];
        $this->assertTrue($frames['publishable']);
        $schema = $frames['capability']->inputSchema;
        $this->assertSame(['frameImages'], $schema['properties']['inputs']['required']);
        $item = $schema['properties']['inputs']['properties']['frameImages']['items']['anyOf'];
        $this->assertSame('init_frame', $item[0]['x-workspace-asset']['role']);
        $this->assertSame('init_frame', $item[1]['properties']['image']['x-workspace-asset']['role']);
        // First/last frames fix the output size, so width and height are not member controls here.
        $this->assertArrayNotHasKey('width', $schema['properties']);
        $this->assertContains('duration', $schema['required']);
        $this->assertSame('async', $frames['provider_bindings']['transport']);

        // Reference-video mode lets the input length decide the output, so per-second billing has nothing to meter.
        $video = $model['operations']['video_to_video'];
        $this->assertFalse($video['publishable']);
        $this->assertStringContainsString('cannot be priced per second', $video['report']['blockers'][0]);
    }

    public function test_per_second_price_without_a_duration_input_needs_handling(): void
    {
        $model = $this->normalize('bria-video-background-removal');

        $this->assertSame(['removeBackground', 'video', 'second'], [$model['task_type'], $model['output_kind'], $model['pricing']['catalog_unit']]);
        $this->assertSame(['video_to_video'], array_keys($model['operations']));
        $operation = $model['operations']['video_to_video'];
        $this->assertFalse($operation['publishable']);
        $this->assertSame(['Runware bills this model per second of output, but this operation has no duration input to meter, so it cannot be priced per second.'],
            $operation['report']['blockers']);
        $this->assertSame('reference_video', $operation['capability']->inputSchema['properties']['inputs']['properties']['video']['x-workspace-asset']['role']);
    }

    public function test_speech_with_a_voice_sample_is_text_to_speech_priced_flat_with_a_character_note(): void
    {
        $model = $this->normalize('alibaba-qwen3-tts-1-7b-base');

        $this->assertSame(['text_to_speech'], array_keys($model['operations']));
        $schema = $model['operations']['text_to_speech']['capability']->inputSchema;
        $this->assertSame(['kind' => 'audio', 'role' => 'audio_reference', 'accepts_url' => true], $schema['properties']['inputs']['properties']['audio']['x-workspace-asset']);
        $this->assertContains('speech', $schema['required']);
        $this->assertSame('generation', $model['pricing']['catalog_unit']);
        $this->assertSame([['amount' => 1.5e-5, 'unit' => 'character', 'label' => null, 'display' => '$0.000015 per character']], $model['pricing']['rates']);
        $this->assertSame('Runware bills this model per character, which token billing cannot meter. The flat token price per result must cover the largest input the schema allows: speech.text up to 2,000 characters.',
            $model['pricing']['note']);
        $example = $model['operations']['text_to_speech']['examples'][0];
        $this->assertSame($example['values']['speech']['text'], $example['prompt']);
    }

    public function test_three_d_results_are_model_files_and_images_drive_image_to_3d(): void
    {
        $model = $this->normalize('tripo-v3-1');

        $this->assertSame(['text_to_3d', 'image_to_3d'], array_keys($model['operations']));
        $this->assertSame('model3d', $model['category']);
        $output = $model['operations']['image_to_3d']['capability']->outputSchema;
        $this->assertSame(['kind' => 'model3d'], $output['properties']['outputs']['properties']['files']['items']['properties']['url']['x-workspace-output']);
        $images = $model['operations']['image_to_3d']['capability']->inputSchema['properties']['inputs'];
        $this->assertSame(['images'], $images['required']);
        $this->assertSame('image', $images['properties']['images']['items']['x-workspace-asset']['kind']);
        $this->assertArrayNotHasKey('inputs', $model['operations']['text_to_3d']['capability']->inputSchema['properties']);
    }

    public function test_image_upscaler_is_an_image_edit_without_a_result_count(): void
    {
        $operation = $this->normalize('clarity')['operations']['image_edit'];

        $this->assertTrue($operation['publishable']);
        $this->assertSame(['upscale', 'runware:500@1', null], [$operation['provider_bindings']['task_type'], $operation['provider_bindings']['endpoint'], $operation['provider_bindings']['quantity_input']]);
        $this->assertSame(['image'], $operation['capability']->inputSchema['properties']['inputs']['required']);
    }

    public function test_deprecated_coming_soon_and_language_models_are_skipped(): void
    {
        $normalizer = new RunwareSchemaNormalizer;

        $this->assertSame('deprecated', $normalizer->describe($this->bundle('bytedance-seedance-1-5-pro'))['skip']);
        $this->assertSame('coming-soon', $normalizer->describe($this->bundle('boogu-image-0-1-edit'))['skip']);
        $language = $normalizer->normalize($this->bundle('anthropic-claude-fable-5'), 'runware/anthropic-claude-fable-5');
        $this->assertSame(['text-to-text', []], [$language['skip'], $language['operations']]);
    }

    public function test_unusable_schemas_become_candidates_that_need_handling_instead_of_failing(): void
    {
        $bundle = $this->bundle('bfl-flux-1-dev');
        $family = $bundle['openapi'];
        unset($family['components']['schemas']['RequestBody']['items']['properties']['model']['const']);
        $family['info']['x-air-id'] = 'flux-1-dev';
        $architecture = $this->normalize('bfl-flux-1-dev', ['openapi' => $family, 'index' => ['id' => 'bfl-flux-1-dev', 'status' => 'live'], 'content' => null]);
        $this->assertNull($architecture['air']);
        $this->assertFalse($architecture['operations']['text_to_image']['publishable']);
        $this->assertStringContainsString('without a fixed model identifier', $architecture['operations']['text_to_image']['report']['blockers'][0]);

        $external = $bundle['openapi'];
        $external['components']['schemas']['RequestBody']['items']['properties']['steps'] = ['$ref' => 'https://schemas.example.test/steps.json'];
        $failed = $this->normalize('bfl-flux-1-dev', ['openapi' => $external]);
        $this->assertSame(['text_to_image'], array_keys($failed['operations']));
        $this->assertNull($failed['operations']['text_to_image']['capability']);
        $this->assertStringContainsString('external or recursive reference', $failed['operations']['text_to_image']['report']['blockers'][0]);

        $missing = $this->normalize('tripo-v3-1', ['openapi' => null, 'fetch_error' => 'The public schema could not be fetched (HTTP 404).']);
        $this->assertSame(['The public schema could not be fetched (HTTP 404).'], $missing['operations']['text_to_3d']['report']['blockers']);
    }

    public function test_normalization_is_deterministic_for_compatibility_checks(): void
    {
        $first = $this->normalize('klingai-video-2-6-pro')['operations']['image_to_video'];
        $second = $this->normalize('klingai-video-2-6-pro')['operations']['image_to_video'];

        $this->assertSame(json_encode($first['capability']->toArray()), json_encode($second['capability']->toArray()));
        $this->assertSame(json_encode($first['provider_bindings']), json_encode($second['provider_bindings']));
    }
}
