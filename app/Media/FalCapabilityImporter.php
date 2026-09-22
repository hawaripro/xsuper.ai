<?php

namespace App\Media;

use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\OutputKind;

/** Provider schemas are evidence, not executable configuration. Only the implemented Studio subset is publishable. */
final class FalCapabilityImporter
{
    private const CATEGORIES = [
        'text-to-image' => [MediaOperation::TextToImage, OutputKind::Image],
        'image-to-image' => [MediaOperation::ImageEdit, OutputKind::Image],
        'text-to-video' => [MediaOperation::TextToVideo, OutputKind::Video],
        'image-to-video' => [MediaOperation::ImageToVideo, OutputKind::Video],
        'text-to-audio' => [MediaOperation::Music, OutputKind::Audio],
        'text-to-speech' => [MediaOperation::TextToSpeech, OutputKind::Audio],
    ];

    public function normalize(array $model): array
    {
        $blockers = [];
        $warnings = [];
        $endpoint = (string) ($model['endpoint_id'] ?? '');
        $document = is_array($model['openapi'] ?? null) ? $model['openapi'] : [];
        $category = (string) ($model['metadata']['category'] ?? data_get($document, 'info.x-fal-metadata.category', ''));
        [$operation, $kind] = self::CATEGORIES[$category] ?? [null, null];
        if (! self::validEndpoint($endpoint)) {
            $blockers[] = 'The provider endpoint ID is not a safe relative model path.';
        }
        if ($operation === null) {
            return $this->result(null, null, [], ["Unsupported category '{$category}'; no executable adapter/Studio mapping."], []);
        }
        if (! in_array($operation, [MediaOperation::TextToImage, MediaOperation::ImageEdit], true)) {
            $blockers[] = 'This operation requires a reviewed operation-specific adapter; generic catalog execution supports images only.';
        }
        $path = $document['paths']['/'.$endpoint] ?? [];
        $inputRef = $path['post']['requestBody']['content']['application/json']['schema'] ?? null;
        $outputRef = $document['paths']['/'.$endpoint.'/requests/{request_id}']['get']['responses']['200']['content']['application/json']['schema'] ?? null;
        if (! is_array($inputRef)) {
            $blockers[] = 'No unambiguous endpoint request schema found.';
            $inputRef = $this->singleComponent($document, 'Input');
        }
        if (! is_array($outputRef)) {
            // Some model documents describe the synchronous response directly.
            $outputRef = $path['post']['responses']['200']['content']['application/json']['schema'] ?? null;
        }
        $input = $this->resolve($inputRef, $document, $blockers, 'input');
        $output = $this->resolve($outputRef, $document, $blockers, 'output');
        $this->constraints($input, 'input', $blockers, ['type', 'properties', 'required', 'additionalProperties']);
        if (($input['type'] ?? null) !== 'object' || ! is_array($input['properties'] ?? null)) {
            $blockers[] = 'Input must be an object with declared properties.';
        }
        $properties = is_array($input['properties'] ?? null) ? $input['properties'] : [];
        $required = is_array($input['required'] ?? null) ? $input['required'] : [];
        foreach ($required as $name) {
            if (! is_string($name) || ! is_array($properties[$name] ?? null)) {
                $blockers[] = 'A required input is missing its property definition.';
            }
        }
        // Order is presentation only. It must never hide a required property from validation.
        $order = array_values(array_unique(array_merge(
            array_filter((array) ($input['x-fal-order-properties'] ?? []), 'is_string'), array_keys($properties),
        )));
        $inputs = [];
        $params = [];
        $bindings = ['adapter' => 'fal_image_v1', 'endpoint' => $endpoint, 'inputs' => [], 'params' => [], 'constants' => [], 'output' => 'images'];
        foreach ($order as $name) {
            if (! is_array($properties[$name] ?? null)) {
                continue;
            }
            $schema = $this->resolve($properties[$name], $document, $blockers, $name);
            $isRequired = in_array($name, $required, true);
            if ($name === 'prompt') {
                $this->constraints($schema, $name, $blockers, ['type']);
                if (($schema['type'] ?? null) !== 'string') {
                    $blockers[] = 'Prompt must be plain text.';
                }
                $inputs[] = new CapabilityInput('prompt', InputRole::Prompt, 'string', required: $isRequired);
                $bindings['inputs']['prompt'] = $name;
            } elseif (in_array($name, ['image_url', 'image_urls'], true)) {
                $schema = $this->resolve($schema, $document, $blockers, $name);
                $multi = ($schema['type'] ?? null) === 'array';
                $this->constraints($schema, $name, $blockers, $multi ? ['type', 'items', 'minItems', 'maxItems'] : ['type', 'format']);
                $item = $multi ? $this->resolve($schema['items'] ?? null, $document, $blockers, $name.'.items') : $schema;
                $this->constraints($item, $name.'.item', $blockers, ['type', 'format']);
                if (($item['type'] ?? null) !== 'string' || (isset($item['format']) && ! in_array($item['format'], ['uri', 'url'], true))
                    || ($schema['minItems'] ?? 0) > 1 || ($schema['maxItems'] ?? 1) < 1) {
                    $blockers[] = "Asset field '{$name}' cannot be represented by the single-reference Studio.";
                }
                if (isset($bindings['inputs']['reference_image'])) {
                    $blockers[] = 'Multiple reference roles require a dedicated Studio mapping.';
                }
                $inputs[] = new CapabilityInput('reference_image', InputRole::ImageRef, 'asset', required: $isRequired);
                $bindings['inputs']['reference_image'] = $name;
                $bindings['reference_array'] = $multi;
            } elseif (in_array($name, ['image_size', 'aspect_ratio'], true)) {
                $enum = $this->enumBranch($schema);
                if ($enum === null) {
                    if ($isRequired) {
                        $blockers[] = "Required field '{$name}' needs a supported discrete size mapping.";
                    } else {
                        $warnings[] = "Optional '{$name}' uses the provider default; custom dimensions are not exposed.";
                    }

                    continue;
                }
                $this->constraints($enum, $name, $blockers, ['type', 'enum']);
                $options = $enum['enum'];
                if (count($options) > 50 || array_filter($options, fn ($value) => ! is_string($value) || strlen($value) > 32) !== []) {
                    $blockers[] = "Field '{$name}' has unsupported size choices.";

                    continue;
                }
                if (isset($bindings['params']['size'])) {
                    $blockers[] = 'Multiple geometry controls require a dedicated mapping.';

                    continue;
                }
                $default = in_array($schema['default'] ?? null, $options, true) ? $schema['default'] : $options[0];
                $params[] = new CapabilityParam('size', 'enum', default: $default, options: $options, required: $isRequired);
                $bindings['params']['size'] = $name;
            } elseif (in_array($name, ['num_images', 'sync_mode', 'enable_safety_checker', 'output_format'], true)) {
                $schema = $this->resolve($schema, $document, $blockers, $name);
                $value = match ($name) {
                    'num_images' => 1, 'sync_mode' => false, 'enable_safety_checker' => true,
                    'output_format' => in_array('png', $schema['enum'] ?? [], true) ? 'png' : ($schema['default'] ?? 'jpeg'),
                };
                $this->constraints($schema, $name, $blockers, ['type', 'enum', 'minimum', 'maximum']);
                if (! $this->acceptsConstant($schema, $value) || ($name === 'output_format' && ! in_array($value, ['png', 'jpeg', 'webp'], true))) {
                    $blockers[] = "Field '{$name}' cannot use the safe fixed runtime value.";
                }
                $bindings['constants'][$name] = $value;
            } elseif ($isRequired) {
                $blockers[] = "Unknown required field '{$name}' needs an implemented binding and Studio control.";
            } else {
                $warnings[] = "Optional field '{$name}' is not exposed; the provider default applies.";
            }
        }
        if (! isset($bindings['inputs']['prompt'])) {
            $blockers[] = 'The image Studio requires a prompt field.';
        }
        if ($operation === MediaOperation::ImageEdit && ! isset($bindings['inputs']['reference_image'])) {
            $blockers[] = 'Image editing requires a mapped reference image.';
        }
        if ($operation === MediaOperation::TextToImage && isset($bindings['inputs']['reference_image'])) {
            $blockers[] = 'Reference-image inputs must be reviewed under the image_edit operation.';
        }
        $this->checkOutput($output, $document, $blockers);
        $capability = new MediaCapability((string) ($model['model_public_id'] ?? $endpoint), $operation, $kind, 1, $inputs, $params);

        return $this->result($capability, $operation->value, $bindings, $blockers, $warnings);
    }

    public static function validEndpoint(string $endpoint): bool
    {
        return strlen($endpoint) <= 160 && preg_match('#^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_.-]+)+$#D', $endpoint) === 1
            && ! str_contains($endpoint, '..');
    }

    /** Stable source hash ignores JSON object key order, but never array order. */
    public static function hash(array $source): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode($canonical($source), JSON_THROW_ON_ERROR));
    }

    private function result(?MediaCapability $capability, ?string $operation, array $bindings, array $blockers, array $warnings): array
    {
        $blockers = array_values(array_unique($blockers));

        return [
            'capability' => $capability, 'operation' => $operation, 'provider_bindings' => $bindings,
            'limitations' => [...array_map(fn ($value) => $value.' This blocks publication.', $blockers), ...$warnings],
            'publishable' => $blockers === [],
            'report' => ['version' => 1, 'compatible' => $blockers === [], 'blockers' => $blockers, 'warnings' => $warnings,
                'test_kind' => 'offline_contract', 'live_verified' => false],
        ];
    }

    private function resolve(mixed $schema, array $document, array &$blockers, string $label, array $seen = []): array
    {
        if (! is_array($schema)) {
            $blockers[] = "Missing {$label} schema.";

            return [];
        }
        foreach (['properties', 'required', 'enum', 'anyOf', 'oneOf', 'allOf'] as $key) {
            if (isset($schema[$key]) && ! is_array($schema[$key])) {
                $blockers[] = "Malformed '{$key}' in {$label}.";

                return [];
            }
        }
        foreach (['required', 'enum', 'anyOf', 'oneOf', 'allOf'] as $key) {
            if (isset($schema[$key]) && ! array_is_list($schema[$key])) {
                $blockers[] = "Non-list '{$key}' in {$label}.";

                return [];
            }
        }
        foreach (['minimum', 'maximum', 'minItems', 'maxItems'] as $key) {
            if (isset($schema[$key]) && (! is_int($schema[$key]) && ! is_float($schema[$key]))) {
                $blockers[] = "Non-numeric '{$key}' in {$label}.";

                return [];
            }
        }
        if (isset($schema['$ref'])) {
            $ref = $schema['$ref'];
            if (! is_string($ref) || ! str_starts_with($ref, '#/') || in_array($ref, $seen, true) || count($seen) >= 16) {
                $blockers[] = "Unsupported or circular reference in {$label}.";

                return [];
            }
            $target = $document;
            foreach (explode('/', substr($ref, 2)) as $part) {
                $target = is_array($target) ? ($target[str_replace(['~1', '~0'], ['/', '~'], $part)] ?? null) : null;
            }
            $resolved = $this->resolve($target, $document, $blockers, $label, [...$seen, $ref]);
            unset($schema['$ref']);

            return array_replace($resolved, $schema);
        }

        return $schema;
    }

    private function singleComponent(array $document, string $suffix): ?array
    {
        $schemas = $document['components']['schemas'] ?? [];
        $matches = is_array($schemas) ? array_filter($schemas, fn ($key) => is_string($key) && str_ends_with($key, $suffix), ARRAY_FILTER_USE_KEY) : [];
        $candidate = count($matches) === 1 ? array_values($matches)[0] : null;

        return is_array($candidate) ? $candidate : null;
    }

    private function enumBranch(array $schema): ?array
    {
        if (is_array($schema['enum'] ?? null) && $schema['enum'] !== []) {
            return $schema;
        }
        // Selecting a discrete branch of anyOf is a safe restriction; oneOf is not interchangeable.
        foreach ($schema['anyOf'] ?? [] as $branch) {
            if (is_array($branch) && ($branch['type'] ?? null) === 'string' && is_array($branch['enum'] ?? null) && $branch['enum'] !== []) {
                $outer = $schema;
                unset($outer['anyOf'], $outer['default']);

                return array_replace($outer, $branch);
            }
        }

        return null;
    }

    private function constraints(array $schema, string $label, array &$blockers, array $supported): void
    {
        $annotations = ['title', 'description', 'default', 'examples', 'example', 'deprecated', 'readOnly', 'writeOnly'];
        foreach (array_keys($schema) as $key) {
            if (! str_starts_with($key, 'x-') && ! in_array($key, [...$annotations, ...$supported], true)) {
                $blockers[] = "Unhandled constraint '{$key}' on {$label}.";
            }
        }
        if (($schema['readOnly'] ?? false) === true || ($schema['writeOnly'] ?? false) === true) {
            $blockers[] = "Directional field constraint on {$label} needs review.";
        }
    }

    private function acceptsConstant(array $schema, mixed $value): bool
    {
        $type = $schema['type'] ?? null;
        $correct = match ($type) {
            'integer' => is_int($value), 'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value), 'string' => is_string($value), default => false,
        };

        return $correct && (! isset($schema['enum']) || in_array($value, $schema['enum'], true))
            && (! isset($schema['minimum']) || $value >= $schema['minimum'])
            && (! isset($schema['maximum']) || $value <= $schema['maximum']);
    }

    private function checkOutput(array $output, array $document, array &$blockers): void
    {
        $this->constraints($output, 'output', $blockers, ['type', 'properties', 'required', 'additionalProperties']);
        $images = $this->resolve($output['properties']['images'] ?? null, $document, $blockers, 'output.images');
        $this->constraints($images, 'output.images', $blockers, ['type', 'items', 'minItems', 'maxItems']);
        $image = $this->resolve($images['items'] ?? null, $document, $blockers, 'output.images.items');
        $this->constraints($image, 'output.images.items', $blockers, ['type', 'properties', 'required', 'additionalProperties']);
        $url = $this->resolve($image['properties']['url'] ?? null, $document, $blockers, 'output.images.items.url');
        $this->constraints($url, 'output image URL', $blockers, ['type', 'format']);
        if (($output['type'] ?? null) !== 'object' || ($images['type'] ?? null) !== 'array'
            || ($image['type'] ?? null) !== 'object' || ($url['type'] ?? null) !== 'string'
            || (isset($url['format']) && ! in_array($url['format'], ['uri', 'url'], true))
            || ! in_array('images', $output['required'] ?? [], true) || ! in_array('url', $image['required'] ?? [], true)
            || ($images['minItems'] ?? 0) > 10 || ($images['maxItems'] ?? 1) < 1) {
            $blockers[] = 'Unsupported image output contract: expected images[] with required public URL; storage accepts PNG/JPEG/WebP only.';
        }
        foreach (array_keys($output['properties'] ?? []) as $field) {
            if (! in_array($field, ['images', 'timings', 'seed', 'has_nsfw_concepts', 'prompt'], true)) {
                $blockers[] = "Unhandled output '{$field}' must not be discarded.";
            }
        }
        foreach (array_keys($image['properties'] ?? []) as $field) {
            if (! in_array($field, ['url', 'width', 'height', 'content_type', 'file_size', 'file_name'], true)) {
                $blockers[] = "Unhandled image output field '{$field}'.";
            }
        }
        foreach ($output['required'] ?? [] as $field) {
            if (! is_string($field) || ! isset($output['properties'][$field])) {
                $blockers[] = 'A required output field has no schema definition.';
            }
        }
        $contentType = $this->resolve($image['properties']['content_type'] ?? [], $document, $blockers, 'output content type');
        if (isset($contentType['default']) && $contentType['default'] !== null && ! in_array($contentType['default'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            $blockers[] = 'The default output file type is unsupported by private image storage.';
        }
        $types = isset($contentType['anyOf']) ? $contentType['anyOf'] : [$contentType];
        foreach ($types as $type) {
            if (! is_array($type) || ($type['type'] ?? null) === 'null') {
                continue;
            }
            $type = $this->resolve($type, $document, $blockers, 'output content type branch');
            $this->constraints($type, 'output content type', $blockers, ['type', 'enum']);
            if (isset($type['enum']) && array_diff($type['enum'], ['image/png', 'image/jpeg', 'image/webp']) !== []) {
                $blockers[] = 'The output declares file types unsupported by private image storage.';
            }
            if (isset($type['default']) && $type['default'] !== null && ! in_array($type['default'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                $blockers[] = 'The default output file type is unsupported by private image storage.';
            }
        }
    }
}
