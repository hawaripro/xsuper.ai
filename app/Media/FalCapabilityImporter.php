<?php

namespace App\Media;

use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\OutputKind;

/**
 * F6a probe: normalize a fal model's OpenAPI-3.0 schema into a MediaCapability draft
 * plus an explicit list of limitations. Heuristic role inference (fal asset fields carry
 * no format:uri marker), so mappings are candidates for curation — NEVER auto-published.
 * A required field that cannot be represented is a publish blocker, never silently dropped.
 * This does NOT fetch, mass-import, or make paid calls; it only normalizes a supplied schema.
 */
final class FalCapabilityImporter
{
    private const ASSET_ROLES = [
        'image_url' => InputRole::ImageRef, 'image_urls' => InputRole::ImageRef, 'images' => InputRole::ImageRef,
        'start_image_url' => InputRole::InitFrame, 'first_image_url' => InputRole::InitFrame,
        'end_image_url' => InputRole::EndFrame, 'last_image_url' => InputRole::EndFrame,
        'audio_url' => InputRole::SpeechAudio, 'video_url' => InputRole::ReferenceVideo,
    ];

    private const CATEGORY_OPERATION = [
        'text-to-image' => [MediaOperation::TextToImage, OutputKind::Image],
        'image-to-image' => [MediaOperation::ImageEdit, OutputKind::Image],
        'text-to-video' => [MediaOperation::TextToVideo, OutputKind::Video],
        'image-to-video' => [MediaOperation::ImageToVideo, OutputKind::Video],
        'text-to-audio' => [MediaOperation::Music, OutputKind::Audio],
        'text-to-speech' => [MediaOperation::TextToSpeech, OutputKind::Audio],
    ];

    /**
     * @param  array<string, mixed>  $model  one entry from GET /v1/models?expand=openapi-3.0
     * @return array{capability: ?MediaCapability, operation: ?string, limitations: array<int, string>, publishable: bool}
     */
    public function normalize(array $model): array
    {
        $limitations = [];
        $endpointId = (string) ($model['endpoint_id'] ?? '');
        $category = (string) ($model['metadata']['category'] ?? data_get($model, 'openapi.info.x-fal-metadata.category', ''));

        [$operation, $outputKind] = self::CATEGORY_OPERATION[$category] ?? [null, null];
        if ($operation === null) {
            return ['capability' => null, 'operation' => null, 'limitations' => ["Unsupported category '{$category}'."], 'publishable' => false];
        }

        $input = $this->inputSchema($model);
        if ($input === null) {
            return ['capability' => null, 'operation' => $operation->value, 'limitations' => ['No input schema found.'], 'publishable' => false];
        }

        $properties = is_array($input['properties'] ?? null) ? $input['properties'] : [];
        $required = is_array($input['required'] ?? null) ? $input['required'] : [];
        $order = is_array($input['x-fal-order-properties'] ?? null) ? $input['x-fal-order-properties'] : array_keys($properties);

        $inputs = [];
        $params = [];
        foreach ($order as $name) {
            $schema = $properties[$name] ?? null;
            if (! is_array($schema)) {
                continue;
            }
            $isRequired = in_array($name, $required, true);

            if ($name === 'prompt') {
                $inputs[] = new CapabilityInput('prompt', InputRole::Prompt, 'string', required: $isRequired);

                continue;
            }
            if (isset(self::ASSET_ROLES[$name])) {
                $multi = ($schema['type'] ?? null) === 'array';
                $inputs[] = new CapabilityInput($name, self::ASSET_ROLES[$name], 'asset', single: ! $multi, max: $multi ? 10 : 1, required: $isRequired);

                continue;
            }

            $param = $this->paramFor($name, $schema);
            if ($param === null) {
                if ($isRequired) {
                    $limitations[] = "Required field '{$name}' is not representable and blocks publication.";
                } else {
                    $limitations[] = "Optional field '{$name}' skipped (unrepresentable).";
                }

                continue;
            }
            $params[] = new CapabilityParam(...[...$param, 'required' => $isRequired]);
        }

        return [
            'capability' => new MediaCapability($endpointId, $operation, $outputKind, 1, $inputs, $params),
            'operation' => $operation->value,
            'limitations' => $limitations,
            'publishable' => ! collect($limitations)->contains(fn (string $l): bool => str_contains($l, 'blocks publication')),
        ];
    }

    /** @return array<string, mixed>|null */
    private function inputSchema(array $model): ?array
    {
        $schemas = data_get($model, 'openapi.components.schemas', []);
        if (! is_array($schemas)) {
            return null;
        }
        foreach ($schemas as $name => $schema) {
            if (is_string($name) && str_ends_with($name, 'Input') && is_array($schema)) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null  named args for CapabilityParam, or null if unrepresentable
     */
    private function paramFor(string $name, array $schema): ?array
    {
        $enum = $schema['enum'] ?? $this->enumFromAnyOf($schema);
        if (is_array($enum) && $enum !== []) {
            return ['name' => $name, 'type' => 'enum', 'default' => $schema['default'] ?? null, 'options' => array_values($enum)];
        }
        $type = $this->scalarType($schema);

        return match ($type) {
            'integer', 'number' => ['name' => $name, 'type' => $type === 'integer' ? 'int' : 'number', 'default' => $schema['default'] ?? null, 'min' => $schema['minimum'] ?? null, 'max' => $schema['maximum'] ?? null],
            'boolean' => ['name' => $name, 'type' => 'bool', 'default' => $schema['default'] ?? null],
            'string' => ['name' => $name, 'type' => 'string', 'default' => $schema['default'] ?? null],
            default => null, // complex object / unresolved $ref-only -> unrepresentable
        };
    }

    /** @param  array<string, mixed>  $schema */
    private function enumFromAnyOf(array $schema): ?array
    {
        foreach ($schema['anyOf'] ?? [] as $branch) {
            if (is_array($branch) && is_array($branch['enum'] ?? null)) {
                return $branch['enum'];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $schema */
    private function scalarType(array $schema): ?string
    {
        if (is_string($schema['type'] ?? null) && $schema['type'] !== 'array' && $schema['type'] !== 'object') {
            return $schema['type'];
        }
        foreach ($schema['anyOf'] ?? [] as $branch) {
            $type = is_array($branch) ? ($branch['type'] ?? null) : null;
            if (is_string($type) && ! in_array($type, ['null', 'array', 'object'], true)) {
                return $type;
            }
        }

        return null;
    }
}
