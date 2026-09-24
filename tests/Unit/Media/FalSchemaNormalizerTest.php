<?php

namespace Tests\Unit\Media;

use App\Media\FalSchemaNormalizer;
use PHPUnit\Framework\TestCase;

class FalSchemaNormalizerTest extends TestCase
{
    private function entry(array $input, array $output, string $category = 'image-to-image', bool $direct = false): array
    {
        $endpoint = 'acme/studio/edit';
        $response = ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Output']]]]];
        $paths = ['/'.$endpoint => ['post' => [
            'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Input']]]],
            'responses' => $response,
        ]]];
        if (! $direct) {
            $paths['/'.$endpoint.'/requests/{request_id}'] = ['get' => ['responses' => $response]];
        }

        return ['endpoint_id' => $endpoint, 'model_public_id' => 'acme/studio-edit', 'metadata' => ['category' => $category],
            'openapi' => array_filter(['openapi' => '3.0.4', 'x-workspace-transport' => $direct ? 'direct' : null,
                'paths' => $paths, 'components' => ['schemas' => ['Input' => $input, 'Output' => $output]]])];
    }

    private function imageOutput(): array
    {
        return ['type' => 'object', 'required' => ['images'], 'properties' => [
            'images' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['url'],
                'properties' => ['url' => ['type' => 'string'], 'content_type' => ['type' => 'string']]]],
            'seed' => ['type' => 'integer'],
        ]];
    }

    public function test_every_source_field_is_kept_and_only_real_file_fields_become_uploads(): void
    {
        $result = (new FalSchemaNormalizer)->normalize($this->entry([
            'type' => 'object', 'required' => ['prompt', 'image_urls'],
            'properties' => [
                'prompt' => ['type' => 'string', 'maxLength' => 5000],
                'image_urls' => ['type' => 'array', 'maxItems' => 4, 'items' => ['type' => 'string', '_fal_ui_field' => 'image']],
                'audio' => ['type' => 'string', 'enum' => ['on', 'off'], 'default' => 'off'],
                'guidance' => ['type' => 'number', 'minimum' => 0, 'maximum' => 20, 'default' => 3.5],
                'seed' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
            ],
        ], $this->imageOutput()));

        $this->assertTrue($result['publishable']);
        $properties = $result['capability']->inputSchema['properties'];
        $this->assertSame(['prompt', 'image_urls', 'audio', 'guidance', 'seed'], array_keys($properties));
        $this->assertSame('image', $properties['image_urls']['items']['x-workspace-asset']['kind']);
        $this->assertTrue($properties['image_urls']['items']['x-workspace-asset']['accepts_url']);
        $this->assertSame(4, $properties['image_urls']['maxItems']);
        $this->assertSame(['on', 'off'], $properties['audio']['enum']);
        $this->assertArrayNotHasKey('x-workspace-asset', $properties['prompt']);
        // The provider receives its own contract; upload annotations stay on the public side.
        $this->assertArrayNotHasKey('x-workspace-asset', $result['provider_bindings']['request_schema']['properties']['image_urls']['items']);
    }

    public function test_union_file_limits_reach_the_upload_branch(): void
    {
        $result = (new FalSchemaNormalizer)->normalize($this->entry([
            'type' => 'object', 'properties' => ['texture_image_url' => [
                'anyOf' => [['type' => 'string', '_fal_ui_field' => 'image'], ['type' => 'null']],
                'max_file_size' => 20971520, 'max_pixels' => 178956970,
            ]],
        ], ['type' => 'object', 'properties' => ['model_mesh' => ['type' => 'object', 'required' => ['url'],
            'properties' => ['url' => ['type' => 'string'], 'file_size' => ['type' => 'integer']]]]], 'text-to-3d'));

        $leaf = $result['capability']->inputSchema['properties']['texture_image_url']['anyOf'][0]['x-workspace-asset'];
        $this->assertSame([20971520, 178956970], [$leaf['max_file_size'], $leaf['max_pixels']]);
        $this->assertSame('model3d', $result['capability']->outputSchema['properties']['model_mesh']['x-workspace-output']['kind']);
    }

    public function test_direct_contract_keeps_documented_constants_server_side(): void
    {
        $result = (new FalSchemaNormalizer)->normalize($this->entry([
            'type' => 'object', 'additionalProperties' => false, 'required' => ['model', 'messages', 'stream'],
            'properties' => [
                'model' => ['type' => 'string', 'const' => 'perceptron', 'default' => 'perceptron'],
                'stream' => ['type' => 'boolean', 'const' => false, 'default' => false],
                'messages' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object']],
            ],
        ], ['type' => 'object', 'properties' => ['choices' => ['type' => 'array']]], 'vision', true));

        $this->assertTrue($result['publishable']);
        $this->assertSame('direct', $result['provider_bindings']['transport']);
        $this->assertSame(['model' => 'perceptron', 'stream' => false], $result['provider_bindings']['constants']);
        $this->assertSame(['messages'], $result['capability']->inputSchema['required']);
        $this->assertArrayNotHasKey('model', $result['capability']->inputSchema['properties']);
    }

    public function test_text_output_fields_are_not_marked_as_downloadable_files(): void
    {
        $result = (new FalSchemaNormalizer)->normalize($this->entry(
            ['type' => 'object', 'properties' => ['image_url' => ['type' => 'string']]],
            ['type' => 'object', 'properties' => [
                'model' => ['type' => 'string'], 'caption' => ['type' => 'string'],
                'images' => $this->imageOutput()['properties']['images'],
            ]],
            'image-to-text',
        ));

        $output = $result['capability']->outputSchema['properties'];
        $this->assertArrayNotHasKey('x-workspace-output', $output['model']);
        $this->assertArrayNotHasKey('x-workspace-output', $output['caption']);
        $this->assertSame('image', $output['images']['items']['x-workspace-output']['kind']);
    }

    public function test_endpoint_without_a_published_request_contract_is_blocked_explicitly(): void
    {
        $entry = $this->entry(['type' => 'object'], $this->imageOutput());
        $entry['openapi']['paths'] = [];

        $result = (new FalSchemaNormalizer)->normalize($entry);

        $this->assertFalse($result['publishable']);
        $this->assertNull($result['capability']);
        $this->assertNotSame([], $result['report']['blockers']);
    }

    public function test_open_object_without_documented_fields_is_blocked_instead_of_offering_an_empty_form(): void
    {
        $open = (new FalSchemaNormalizer)->normalize($this->entry(['type' => 'object', 'additionalProperties' => true], $this->imageOutput()));
        $closed = (new FalSchemaNormalizer)->normalize($this->entry(['type' => 'object', 'additionalProperties' => false], $this->imageOutput()));

        $this->assertFalse($open['publishable']);
        $this->assertNotSame([], $open['report']['blockers']);
        // A closed empty object is a real "no inputs" contract and stays runnable.
        $this->assertTrue($closed['publishable']);
    }
}
