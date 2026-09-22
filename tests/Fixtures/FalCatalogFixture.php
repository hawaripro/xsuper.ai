<?php

namespace Tests\Fixtures;

final class FalCatalogFixture
{
    public static function model(string $endpoint = 'fal-ai/catalog-fixture', array $sizes = ['square', 'portrait']): array
    {
        return [
            'endpoint_id' => $endpoint,
            'metadata' => ['category' => 'text-to-image', 'display_name' => 'Upstream label', 'status' => 'active'],
            'openapi' => [
                'openapi' => '3.0.4', 'info' => ['version' => '1.0'],
                'paths' => [
                    '/'.$endpoint => ['post' => ['requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Input']]]]]],
                    '/'.$endpoint.'/requests/{request_id}' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Output']]]]]]],
                ],
                'components' => ['schemas' => [
                    'Input' => ['type' => 'object', 'required' => ['prompt'], 'x-fal-order-properties' => ['prompt', 'image_size'], 'properties' => [
                        'prompt' => ['type' => 'string'],
                        'image_size' => ['type' => 'string', 'enum' => $sizes, 'default' => $sizes[0]],
                        'num_images' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'default' => 1],
                        'sync_mode' => ['type' => 'boolean', 'default' => false],
                    ]],
                    'Output' => ['type' => 'object', 'required' => ['images'], 'properties' => [
                        'images' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Image']],
                    ]],
                    'Image' => ['type' => 'object', 'required' => ['url'], 'properties' => ['url' => ['type' => 'string']]],
                ]],
            ],
        ];
    }
}
