<?php

namespace App\Media;

use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\OutputKind;
use InvalidArgumentException;
use Throwable;

/** Converts complete provider evidence into the version-2 workspace contract. */
final class FalSchemaNormalizer
{
    private const CATEGORIES = [
        'text-to-image' => [MediaOperation::TextToImage, OutputKind::Image],
        'image-to-image' => [MediaOperation::ImageEdit, OutputKind::Image],
        'text-to-video' => [MediaOperation::TextToVideo, OutputKind::Video],
        'image-to-video' => [MediaOperation::ImageToVideo, OutputKind::Video],
        'audio-to-video' => [MediaOperation::AudioToVideo, OutputKind::Video],
        'video-to-video' => [MediaOperation::VideoToVideo, OutputKind::Video],
        'video-to-audio' => [MediaOperation::VideoToAudio, OutputKind::Audio],
        'video-to-text' => [MediaOperation::VideoToText, OutputKind::Data],
        'text-to-audio' => [MediaOperation::TextToAudio, OutputKind::Audio],
        'text-to-speech' => [MediaOperation::TextToSpeech, OutputKind::Audio],
        'audio-to-audio' => [MediaOperation::AudioToAudio, OutputKind::Audio],
        'audio-to-text' => [MediaOperation::AudioToText, OutputKind::Data],
        'speech-to-text' => [MediaOperation::SpeechToText, OutputKind::Data],
        'speech-to-speech' => [MediaOperation::SpeechToSpeech, OutputKind::Audio],
        'text-to-3d' => [MediaOperation::TextTo3d, OutputKind::Model3d],
        'image-to-3d' => [MediaOperation::ImageTo3d, OutputKind::Model3d],
        '3d-to-3d' => [MediaOperation::Model3dToModel3d, OutputKind::Model3d],
        'image-to-text' => [MediaOperation::ImageToText, OutputKind::Data],
        'image-to-json' => [MediaOperation::ImageToJson, OutputKind::Data],
        'text-to-json' => [MediaOperation::TextToJson, OutputKind::Data],
        'text-to-text' => [MediaOperation::TextToText, OutputKind::Data],
        'vision' => [MediaOperation::Vision, OutputKind::Data],
        'llm' => [MediaOperation::LanguageModel, OutputKind::Data],
        'json' => [MediaOperation::StructuredData, OutputKind::Data],
        'training' => [MediaOperation::Training, OutputKind::File],
        'workflow' => [MediaOperation::Workflow, OutputKind::Data],
        'unknown' => [MediaOperation::Inference, OutputKind::Data],
    ];

    public function normalize(array $model): array
    {
        $endpoint = (string) ($model['endpoint_id'] ?? '');
        $document = is_array($model['openapi'] ?? null) ? $model['openapi'] : [];
        $category = strtolower((string) ($model['metadata']['category'] ?? data_get($document, 'info.x-fal-metadata.category', 'unknown')));
        [$operation, $preferredKind] = self::CATEGORIES[$category] ?? [MediaOperation::Inference, OutputKind::Data];
        $warnings = (array) data_get($document, 'x-workspace-fal-documentation.warnings', []);
        $bindings = [];
        try {
            if (! FalCapabilityImporter::validEndpoint($endpoint)) {
                throw new InvalidArgumentException('The provider endpoint ID is not a safe relative model path.');
            }
            $path = $document['paths']['/'.$endpoint] ?? [];
            $request = $path['post']['requestBody']['content']['application/json']['schema'] ?? null;
            if (! is_array($request) && ! is_bool($request)) {
                throw new InvalidArgumentException('The provider has not published an executable request schema for this exact endpoint.');
            }
            $direct = ($document['x-workspace-transport'] ?? null) === 'direct';
            $response = $direct
                ? ($path['post']['responses']['200']['content']['application/json']['schema'] ?? null)
                : ($document['paths']['/'.$endpoint.'/requests/{request_id}']['get']['responses']['200']['content']['application/json']['schema'] ?? null);
            if (! is_array($response) && ! is_bool($response)) {
                throw new InvalidArgumentException('The provider has not published the result contract for this exact endpoint.');
            }
            $requestSchema = MediaJsonSchema::resolve($request, $document);
            $responseSchema = MediaJsonSchema::resolve($response, $document);
            if ($requestSchema === false || $responseSchema === false) {
                throw new InvalidArgumentException('The provider contract explicitly forbids a request or result.');
            }
            $requestSchema = $requestSchema === true ? ['type' => 'object', 'additionalProperties' => true] : $requestSchema;
            $responseSchema = $responseSchema === true ? [] : $responseSchema;
            $composed = array_intersect_key($requestSchema, array_flip(['patternProperties', 'allOf', 'anyOf', 'oneOf']));
            if (($requestSchema['type'] ?? 'object') === 'object' && ($requestSchema['properties'] ?? []) === [] && $composed === []
                && ($requestSchema['additionalProperties'] ?? true) !== false) {
                // An open object documents no fields: a form could not express a meaningful request.
                throw new InvalidArgumentException('The provider publishes only an open object without documented request fields for this exact endpoint.');
            }
            // Parsing the schemas checks their structure; null need not be a valid model input.
            MediaJsonSchema::errors($requestSchema, null);
            MediaJsonSchema::errors($responseSchema, null);
            $input = self::annotate($requestSchema, [], true, $preferredKind);
            $constants = [];
            if ($direct && is_array($input)) {
                // Documented fixed request members (e.g. the provider's model alias) are server-controlled.
                foreach ((array) ($input['properties'] ?? []) as $name => $property) {
                    if (is_array($property) && array_key_exists('const', $property) && array_key_exists('default', $property)
                        && $property['default'] === $property['const'] && in_array($name, (array) ($input['required'] ?? []), true)) {
                        $constants[$name] = $property['const'];
                        unset($input['properties'][$name]);
                        $input['required'] = array_values(array_diff((array) $input['required'], [$name]));
                    }
                }
                if (($input['required'] ?? null) === []) {
                    unset($input['required']);
                }
            }
            $output = self::annotate($responseSchema, [], false, $preferredKind);
            $parts = explode('/', $endpoint);
            $rootLength = in_array($parts[0], ['workflows', 'comfy'], true) ? 3 : 2;
            if (count($parts) < $rootLength) {
                throw new InvalidArgumentException('The provider application identity is incomplete.');
            }
            $bindings = [
                'adapter' => 'fal_schema_v2', 'endpoint' => $endpoint,
                'transport' => $direct ? 'direct' : 'queue',
                'request_schema' => $requestSchema, 'output_schema' => $output, 'constants' => $constants,
                'queue_root' => implode('/', array_slice($parts, 0, $rootLength)),
            ];
            $kind = self::outputKind($output, $preferredKind);
            $capability = new MediaCapability(
                (string) ($model['model_public_id'] ?? $endpoint), $operation, $kind, 2,
                inputSchema: $input, outputSchema: $output,
            );

            return $this->result($capability, $operation, $bindings, [], $warnings);
        } catch (Throwable $exception) {
            return $this->result(null, $operation, $bindings, [$exception instanceof InvalidArgumentException
                ? $exception->getMessage() : 'The published provider JSON Schema is malformed: '.class_basename($exception)], $warnings);
        }
    }

    /**
     * Preserve every source field and constraint. File fields accept an owned upload (UUID) or a
     * public HTTPS link; $inherited carries a union/array's file hint and limits to its branches.
     */
    private static function annotate(array|bool $schema, array $path, bool $input, OutputKind $preferred, array $inherited = []): array|bool
    {
        if (is_bool($schema)) {
            return $schema;
        }
        $name = strtolower(implode('.', $path));
        $hint = strtolower((string) ($schema['_fal_ui_field'] ?? $schema['ui']['field'] ?? $inherited['hint'] ?? ''));
        $type = $schema['type'] ?? null;
        $limits = array_filter([
            'max_file_size' => is_numeric($schema['max_file_size'] ?? null) ? (int) $schema['max_file_size'] : ($inherited['max_file_size'] ?? null),
            'max_pixels' => is_numeric($schema['max_pixels'] ?? null) ? (int) $schema['max_pixels'] : ($inherited['max_pixels'] ?? null),
        ], static fn (?int $value): bool => $value !== null);
        $scalarChoice = isset($schema['enum']) || array_key_exists('const', $schema);
        $linkFormat = ! isset($schema['format']) || in_array($schema['format'], ['uri', 'url', 'iri', 'uri-reference', 'binary', 'byte', 'data-url'], true);
        if ($input && ! $scalarChoice && $linkFormat && ($type === 'string' || ($type === null && isset($schema['format'])))) {
            $kind = self::fileKind($name, $hint, (string) ($schema['description'] ?? ''));
            $isFile = $kind !== null && ($hint !== '' || preg_match('/(?:^|[._])(?:url|urls|path|file|files|image|images|audio|video|mesh|model_url)$/i', $name));
            if (isset($schema['x-workspace-asset']) || $isFile) {
                $kind ??= (string) ($schema['x-workspace-asset']['kind'] ?? 'file');
                $role = match ($kind) {
                    'image' => str_contains($name, 'mask') ? InputRole::MaskImage : InputRole::ImageRef,
                    'audio' => InputRole::AudioReference,
                    'video' => InputRole::ReferenceVideo,
                    'model3d' => InputRole::ModelReference,
                    'document' => InputRole::Document,
                    default => InputRole::GenericFile,
                };
                $annotations = array_intersect_key($schema, array_flip(['title', 'description', 'deprecated']));
                // Omission keeps the provider's own default; examples are provider-hosted sample links.
                $schema = [...$annotations, 'type' => 'string', 'minLength' => 1, 'maxLength' => 2048,
                    'x-workspace-asset' => ['kind' => $kind, 'role' => $role->value, ...$limits, 'accepts_url' => true]];
            }
        }
        $branchContext = array_filter(['hint' => $hint, ...$limits]);
        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $keyword) {
            foreach ($schema[$keyword] ?? [] as $index => $child) {
                $schema[$keyword][$index] = self::annotate($child, $path, $input, $preferred, $branchContext);
            }
        }
        foreach (['properties', 'patternProperties'] as $keyword) {
            foreach ($schema[$keyword] ?? [] as $key => $child) {
                $schema[$keyword][$key] = self::annotate($child, [...$path, $key], $input, $preferred);
            }
        }
        foreach (['items', 'contains', 'unevaluatedItems'] as $keyword) {
            if (isset($schema[$keyword]) && (is_array($schema[$keyword]) || is_bool($schema[$keyword]))) {
                $schema[$keyword] = self::annotate($schema[$keyword], $path, $input, $preferred, $branchContext);
            }
        }
        foreach (['additionalProperties', 'not', 'if', 'then', 'else', 'propertyNames', 'unevaluatedProperties'] as $keyword) {
            if (isset($schema[$keyword]) && (is_array($schema[$keyword]) || is_bool($schema[$keyword]))) {
                $schema[$keyword] = self::annotate($schema[$keyword], $path, $input, $preferred);
            }
        }
        if ($input && isset($schema['default']) && self::hasAssets($schema)) {
            unset($schema['default']);
        }
        if ($input && isset($schema['properties']['url']['x-workspace-asset']) && array_diff((array) ($schema['required'] ?? []), ['url']) === []) {
            // A provider File object: the upload/link is the field; other members are optional metadata.
            $schema['x-workspace-file-object'] = true;
        }
        if (! $input) {
            $fileObject = isset($schema['properties']['url']) && count(array_intersect(['content_type', 'file_name', 'file_size', 'width', 'height'], array_keys($schema['properties']))) > 0;
            $fileString = $type === 'string' && ! $scalarChoice && ($hint !== ''
                || preg_match('/(?:^|[._])(?:image|images|video|audio|mesh|glb|gltf|obj|stl|ply|fbx|file|files|archive|zip|mask)(?:_url|_urls)?$/i', $name));
            if ($fileObject || $fileString) {
                $kind = self::fileKind($name, $hint, (string) ($schema['description'] ?? ''));
                $kind ??= in_array($preferred, [OutputKind::Image, OutputKind::Video, OutputKind::Audio, OutputKind::Model3d], true) ? $preferred->value : 'file';
                $schema['x-workspace-output'] = ['kind' => $kind];
                if (($schema['format'] ?? null) === 'byte' || ($schema['contentEncoding'] ?? null) === 'base64') {
                    $schema['x-workspace-output']['encoding'] = 'base64';
                }
            }
        }

        return $schema;
    }

    private static function fileKind(string $name, string $hint, string $description): ?string
    {
        if (in_array($hint, ['image', 'audio', 'video'], true)) {
            return $hint;
        }
        if (preg_match('/(?:archive|dataset|images_data|weights|lora|checkpoint|safetensors|zip)/i', $name.' '.$description)) {
            return 'file';
        }
        if (preg_match('/(?:mesh|model_?(?:3d|url)|glb|gltf|\.obj|stl|ply|fbx|3d_file)/i', $name)) {
            return 'model3d';
        }
        if (preg_match('/(?:image|photo|picture|mask|frame|texture|normal_map|albedo)/i', $name)) {
            return 'image';
        }
        if (preg_match('/(?:audio|speech|sound|voice|music)/i', $name)) {
            return 'audio';
        }
        if (str_contains($name, 'video')) {
            return 'video';
        }
        if (preg_match('/(?:document|pdf)/i', $name)) {
            return 'document';
        }
        if ($hint === 'file' || preg_match('/(?:^|[._])(?:file|files|url|urls|path)$/i', $name)) {
            return 'file';
        }

        return null;
    }

    private static function hasAssets(array|bool $schema): bool
    {
        if (! is_array($schema)) {
            return false;
        }
        if (isset($schema['x-workspace-asset'])) {
            return true;
        }
        foreach ($schema as $value) {
            if (is_array($value)) {
                if (isset($value['x-workspace-asset'])) {
                    return true;
                }
                foreach ($value as $child) {
                    if (is_array($child) && self::hasAssets($child)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function outputKind(array $schema, OutputKind $preferred): OutputKind
    {
        if (in_array($preferred, [OutputKind::Data, OutputKind::File], true)) {
            return $preferred;
        }
        $kinds = [];
        $walk = static function (mixed $node) use (&$walk, &$kinds): void {
            if (! is_array($node)) {
                return;
            }
            if (is_string($node['x-workspace-output']['kind'] ?? null)) {
                $kinds[$node['x-workspace-output']['kind']] = true;
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($schema);
        if (isset($kinds[$preferred->value]) || $kinds === []) {
            return $preferred;
        }
        foreach ([OutputKind::Model3d, OutputKind::Video, OutputKind::Audio, OutputKind::Image, OutputKind::File] as $kind) {
            if (isset($kinds[$kind->value])) {
                return $kind;
            }
        }

        return OutputKind::Data;
    }

    private function result(?MediaCapability $capability, MediaOperation $operation, array $bindings, array $blockers, array $warnings): array
    {
        return [
            'capability' => $capability, 'operation' => $operation->value, 'provider_bindings' => $bindings,
            'limitations' => [...$blockers, ...$warnings], 'publishable' => $capability !== null && $blockers === [],
            'report' => ['version' => 2, 'normalizer_version' => 2, 'compatible' => $capability !== null && $blockers === [],
                'blockers' => $blockers, 'warnings' => $warnings, 'test_kind' => 'offline_contract', 'live_verified' => false],
        ];
    }
}
