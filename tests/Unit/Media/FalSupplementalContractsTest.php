<?php

namespace Tests\Unit\Media;

use App\Media\FalCapabilityImporter;
use App\Media\FalSupplementalContracts;
use App\Media\MediaJsonSchema;
use Tests\TestCase;

class FalSupplementalContractsTest extends TestCase
{
    private const ISAAC = 'perceptron/isaac-01/openai/v1/chat/completions';

    private const NOT_AVAILABLE = ['error' => ['code' => 'expansion_failed', 'message' => 'OpenAPI schema not available for this endpoint']];

    public function test_documented_direct_recovery_preserves_the_original_source_and_public_identity(): void
    {
        $source = $this->source('isaac-01.queue-incomplete.json');
        $entry = [
            'endpoint_id' => self::ISAAC,
            'model_public_id' => 'reviewed/isaac-vision',
            'metadata' => ['category' => 'vision', 'status' => 'active'],
            'openapi' => $source,
        ];

        $recovered = FalSupplementalContracts::enrich($entry);
        $normalized = (new FalCapabilityImporter)->normalize($recovered, 2);

        $this->assertTrue($normalized['publishable'], implode('; ', $normalized['report']['blockers']));
        $this->assertSame('reviewed/isaac-vision', $normalized['capability']->modelPublicId);
        $this->assertSame(self::ISAAC, $normalized['provider_bindings']['endpoint']);
        $this->assertSame('direct', $normalized['provider_bindings']['transport']);
        $this->assertSame(['model' => 'perceptron', 'stream' => false], $normalized['provider_bindings']['constants']);
        $this->assertSame($source, $recovered['source_evidence']['original']['openapi']);
        $this->assertSame('210013d29c62a8de66bb64926cf6332d2990d5168fafedee5d548230ee4bf668', $recovered['source_evidence']['original']['source_hash']);
        $this->assertSame($entry['metadata'], $recovered['metadata']);
        $this->assertSame($recovered, FalSupplementalContracts::enrich($recovered));

        // Fal's documented image + question request runs with an owned upload; the fixed members are not user input.
        $schema = $normalized['capability']->inputSchema;
        $inputs = ['messages' => [['role' => 'user', 'content' => [
            ['type' => 'image_url', 'image_url' => ['url' => '9b2f8c1e-4d3a-4f5b-8c6d-7e8f9a0b1c2d']],
            ['type' => 'text', 'text' => 'What is visible?'],
        ]]]];
        $this->assertSame([], MediaJsonSchema::errors($schema, $inputs));
        $this->assertSame(['image_ref'], array_column(MediaJsonSchema::assetReferences($schema, $inputs), 'role'));
        $this->assertNotSame([], MediaJsonSchema::errors($schema, [...$inputs, 'model' => 'isaac-0.1']));
    }

    public function test_director_recovers_realtime_without_borrowing_the_schema_service_identity(): void
    {
        $recovered = FalSupplementalContracts::enrich([
            'endpoint_id' => 'minimax/h3-max/director',
            'metadata' => ['category' => 'text-to-video'],
            'openapi' => self::NOT_AVAILABLE,
        ]);
        $normalized = (new FalCapabilityImporter)->normalize($recovered, 2);

        $this->assertTrue($normalized['publishable'], implode('; ', $normalized['report']['blockers']));
        $this->assertSame('fal_wma_v1', $normalized['provider_bindings']['adapter']);
        $this->assertSame('minimax/h3-max/director', $normalized['provider_bindings']['endpoint']);
        $this->assertSame(self::NOT_AVAILABLE, $recovered['source_evidence']['original']['openapi']);
        $this->assertSame('08f40daf6cdfe1ac3a4b794ab572ff9d865be4ccdfcda1014c99454f90a0f003', $recovered['source_evidence']['original']['source_hash']);
    }

    public function test_an_endpoint_without_any_captured_export_is_still_recovered(): void
    {
        foreach ([[], ['openapi' => null], ['openapi' => []]] as $source) {
            $recovered = FalSupplementalContracts::enrich(['endpoint_id' => 'minimax/h3-max/director', 'metadata' => [], ...$source]);

            $this->assertSame('3.1.0', $recovered['openapi']['asyncapi'] ?? null);
            $this->assertSame($source !== [], $recovered['source_evidence']['original']['openapi_present']);
        }
    }

    public function test_a_changed_export_is_not_overwritten_even_if_it_is_still_incomplete(): void
    {
        $source = $this->source('isaac-01.queue-incomplete.json');
        $source['components']['schemas']['Isaac01OpenaiV1ChatCompletionsInput'] = [
            'type' => 'object', 'required' => ['new_field'], 'properties' => ['new_field' => ['type' => 'string']],
        ];
        $entry = ['endpoint_id' => self::ISAAC, 'openapi' => $source];

        $this->assertSame($entry, FalSupplementalContracts::enrich($entry));
    }

    public function test_unresolved_gaps_and_neighbouring_identities_are_never_recovered(): void
    {
        foreach ([
            'fal-ai/controlfoley', 'fal-ai/stable-cascade', 'fal-ai/invisible-watermark', 'fal-ai/stable-cascade/sote-diffusion',
            'fal-ai/decart/lucy-5b/image-to-video', 'fal-ai/hunyuan-video-img2vid-lora',
            'decart/lucy-5b/image-to-video', 'fal-ai/minimax-h3-max-director', 'perceptron/isaac-01',
        ] as $endpoint) {
            foreach ([null, self::NOT_AVAILABLE] as $source) {
                $entry = ['endpoint_id' => $endpoint, 'openapi' => $source, 'metadata' => ['status' => 'deprecated']];
                $this->assertSame($entry, FalSupplementalContracts::enrich($entry), $endpoint);
            }
        }
    }

    public function test_direct_contract_accepts_documented_images_but_not_undocumented_options_or_streaming(): void
    {
        $document = $this->source('isaac-01.documented-direct.openapi.json');
        $schema = MediaJsonSchema::resolve($document['paths']['/'.self::ISAAC]['post']['requestBody']['content']['application/json']['schema'], $document);
        $request = [
            'model' => 'perceptron', 'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => 'Describe the image.'],
                ['role' => 'user', 'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.png']],
                    ['type' => 'text', 'text' => 'What is visible?'],
                ]],
            ],
        ];

        $this->assertSame([], MediaJsonSchema::errors($schema, $request));
        foreach ([
            'streaming' => ['stream' => true],
            'provider-hosted model' => ['model' => 'isaac-0.1'],
            'nested extension body' => ['extra_body' => ['response_style' => 'box']],
            'flattened extension' => ['response_style' => 'box'],
            'undocumented option' => ['temperature' => 0.7],
            'empty conversation' => ['messages' => []],
            'video part' => ['messages' => [['role' => 'user', 'content' => [
                ['type' => 'video_url', 'video_url' => ['url' => 'https://example.com/clip.mp4']],
            ]]]],
        ] as $case => $change) {
            $this->assertNotSame([], MediaJsonSchema::errors($schema, [...$request, ...$change]), $case);
        }
    }

    private function source(string $file): array
    {
        return json_decode(file_get_contents(resource_path('media/fal/'.$file)), true, flags: JSON_THROW_ON_ERROR);
    }
}
