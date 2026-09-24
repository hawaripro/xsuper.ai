<?php

namespace Tests\Unit\Media;

use App\Media\Exceptions\CapabilityValidationException;
use App\Media\MediaJsonSchema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MediaJsonSchemaTest extends TestCase
{
    private const OWNED = '5f0c6b86-8a3e-4b8e-9a2b-1c2d3e4f5a6b';

    private function fileLeaf(): array
    {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 2048,
            'x-workspace-asset' => ['kind' => 'image', 'role' => 'image_ref', 'accepts_url' => true]];
    }

    public function test_declared_fields_are_not_reported_as_additional_when_a_sibling_is_invalid(): void
    {
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['prompt', 'options'],
            'properties' => ['prompt' => ['type' => 'string'], 'options' => ['type' => 'object', 'minProperties' => 1]]];

        $this->assertSame(['options'], array_keys(MediaJsonSchema::errors($schema, ['prompt' => 'a cat', 'options' => []])));

        $errors = MediaJsonSchema::errors($schema, ['prompt' => 'a cat', 'options' => ['k' => 1], 'extra' => true]);
        $this->assertSame(['inputs'], array_keys($errors));
        $this->assertStringContainsString('extra', $errors['inputs']);
        $this->assertStringNotContainsString('prompt', $errors['inputs']);
    }

    public function test_file_fields_take_an_owned_upload_or_a_public_link_and_only_uploads_are_staged(): void
    {
        $schema = ['type' => 'object', 'properties' => ['image_urls' => ['type' => 'array', 'items' => $this->fileLeaf()]]];
        $values = MediaJsonSchema::normalize($schema, ['image_urls' => [self::OWNED, 'https://cdn.example.org/cat.png']]);

        $references = MediaJsonSchema::assetReferences($schema, $values);
        $this->assertSame([['image_urls', 0]], array_column($references, 'path'));
        $this->assertSame([self::OWNED], array_column($references, 'asset_id'));

        $staged = MediaJsonSchema::replaceAssets($schema, $values, fn (array $asset): string => 'https://storage.example/'.$asset['asset_id']);
        $this->assertSame(['https://storage.example/'.self::OWNED, 'https://cdn.example.org/cat.png'], $staged['image_urls']);
    }

    #[DataProvider('unsafeLinks')]
    public function test_links_that_are_not_public_https_are_rejected_before_submission(string $link): void
    {
        $schema = ['type' => 'object', 'properties' => ['image_url' => $this->fileLeaf(), 'webhook' => ['type' => 'string', 'format' => 'uri']]];
        foreach (['image_url', 'webhook'] as $field) {
            try {
                MediaJsonSchema::normalize($schema, [$field => $link]);
                $this->fail("{$field} accepted {$link}");
            } catch (CapabilityValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    public static function unsafeLinks(): array
    {
        return [
            'plain http' => ['http://example.com/cat.png'],
            'credentials' => ['https://user:secret@example.com/cat.png'],
            'localhost' => ['https://localhost/cat.png'],
            'private address' => ['https://10.1.2.3/cat.png'],
            'loopback ipv6' => ['https://[::1]/cat.png'],
            'local suffix' => ['https://printer.local/cat.png'],
            'single label host' => ['https://intranet/cat.png'],
            'shorthand loopback' => ['https://127.1/cat.png'],
            'hex loopback' => ['https://0x7f.0.0.1/cat.png'],
            'shorthand private' => ['https://10.1/cat.png'],
            'mapped link-local ipv6' => ['https://[::ffff:169.254.169.254]/latest'],
            'carrier-grade nat' => ['https://100.64.0.1/cat.png'],
            'encoded backslash' => ['https://example.com%5C@internal.test/cat.png'],
            'encoded control character' => ['https://example.com/cat%00.png'],
            'onion service' => ['https://example.onion/cat.png'],
        ];
    }

    public function test_absent_fields_take_defaults_but_explicit_values_are_kept(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'safety' => ['type' => 'boolean', 'default' => true],
            'steps' => ['type' => 'integer', 'default' => 28],
            'seed' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']], 'default' => 42],
        ]];

        $values = MediaJsonSchema::normalize($schema, ['safety' => false, 'seed' => null]);

        $this->assertFalse($values['safety']);
        $this->assertNull($values['seed']);
        $this->assertSame(28, $values['steps']);
    }

    public function test_declared_empty_objects_survive_provider_json(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'options' => ['type' => 'object'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]];

        $this->assertSame('{"options":{},"tags":[]}', json_encode(MediaJsonSchema::objectMembers($schema, ['options' => [], 'tags' => []])));
    }

    public function test_legacy_tuple_items_check_each_position_and_reject_extras(): void
    {
        $schema = MediaJsonSchema::resolve(['type' => 'array', 'items' => [['type' => 'integer'], ['type' => 'string']], 'additionalItems' => false], []);

        $this->assertSame([], MediaJsonSchema::errors($schema, [512, 'edge']));
        $this->assertNotSame([], MediaJsonSchema::errors($schema, ['512', 'edge']));
        $this->assertNotSame([], MediaJsonSchema::errors($schema, [512, 'edge', 3]));
    }

    public function test_recursive_provider_references_are_refused_instead_of_expanding_forever(): void
    {
        $document = ['components' => ['schemas' => ['Node' => ['type' => 'object', 'properties' => ['child' => ['$ref' => '#/components/schemas/Node']]]]]];

        $this->expectException(InvalidArgumentException::class);
        MediaJsonSchema::resolve(['$ref' => '#/components/schemas/Node'], $document);
    }
}
