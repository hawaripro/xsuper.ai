<?php

namespace App\Media;

use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\OutputKind;
use InvalidArgumentException;
use Throwable;

/**
 * Turns one public Runware model (index entry, per-model OpenAPI schema, content metadata and
 * examples) into version-2 workspace contracts, one per operation derived from the schema's
 * x-modes. Pure: no database or network. Anything that cannot be expressed safely becomes a
 * blocker on the candidate (status needs_handling), never an exception.
 */
final class RunwareSchemaNormalizer
{
    public const VERSION = 1;

    /** A Runware model identifier (AIR), e.g. runware:101@1 or klingai:kling-video@2.6-pro. */
    public const AIR_PATTERN = '[A-Za-z0-9._-]{1,100}:[A-Za-z0-9._-]{1,100}@[A-Za-z0-9._-]{1,100}';

    /** Request members owned by the adapter and the reviewed binding, never by member input. */
    public const TRANSPORT_FIELDS = ['taskType', 'taskUUID', 'model', 'webhookURL', 'uploadEndpoint', 'deliveryMethod', 'outputType', 'ttl', 'includeCost'];

    /** Billing settles or releases a reservation whole, so one task requests at most this many results. */
    public const MAX_RESULTS = 4;

    /** Result members kept only in the private provider result. */
    private const PRIVATE_RESULT_FIELDS = ['cost', 'taskUUID', 'taskType', 'status'];

    /** Inputs that reuse provider-side state shared by every member of the account. */
    private const STATE_INPUTS = ['videoId', 'draftId', 'draftCache', 'styleId', 'checkpoint', 'dataset', 'elements'];

    private const LANGUAGE_TASKS = ['textInference', 'promptEnhance'];

    private const OUTPUTS = [
        'imageInference' => OutputKind::Image, 'controlNetPreprocess' => OutputKind::Image, 'imageMasking' => OutputKind::Image,
        'vectorize' => OutputKind::Image, 'videoInference' => OutputKind::Video, 'audioInference' => OutputKind::Audio,
        '3dInference' => OutputKind::Model3d, 'caption' => OutputKind::Data,
    ];

    /** Runware io capability → workspace operation (C2). */
    private const CAPABILITY_OPERATIONS = [
        'text-to-image' => MediaOperation::TextToImage, 'image-to-image' => MediaOperation::ImageEdit,
        'text-to-video' => MediaOperation::TextToVideo, 'image-to-video' => MediaOperation::ImageToVideo,
        'video-to-video' => MediaOperation::VideoToVideo, 'audio-to-video' => MediaOperation::AudioToVideo,
        'text-to-audio' => MediaOperation::TextToAudio, 'audio-to-audio' => MediaOperation::AudioToAudio,
        'video-to-audio' => MediaOperation::VideoToAudio, 'text-to-3d' => MediaOperation::TextTo3d,
        'image-to-3d' => MediaOperation::ImageTo3d, 'image-to-text' => MediaOperation::ImageToText,
        'video-to-text' => MediaOperation::VideoToText, 'audio-to-text' => MediaOperation::AudioToText,
    ];

    /** Playground sections; anything else is a core parameter. */
    private const GROUPS = [
        'inputs' => 'inputs',
        'hiresFix' => 'features', 'pulid' => 'features', 'ultralytics' => 'features', 'photoMaker' => 'features',
        'acePlusPlus' => 'features', 'refiner' => 'features',
        'advancedFeatures' => 'advanced', 'providerSettings' => 'advanced', 'acceleratorOptions' => 'advanced',
        'acceleration' => 'advanced', 'outputFormat' => 'advanced', 'outputQuality' => 'advanced', 'safety' => 'advanced',
        'audioSettings' => 'advanced',
    ];

    private const KIND_WORDS = [
        'model3d' => ['3d model', 'mesh', 'glb'],
        'image' => ['image', 'photo', 'picture', 'frame'],
        'video' => ['video'],
        'audio' => ['audio', 'voice', 'speech', 'sound'],
        'document' => ['document', 'pdf'],
        'file' => ['zip', 'archive', 'dataset'],
    ];

    /**
     * Import identity and the skip decision, readable before any contract is built.
     *
     * @return array{model_id: string, schema_url: string, skip: ?string, air: ?string, task_type: ?string, name: string, description: ?string, logo_url: ?string, available: bool, capabilities: list<string>}
     */
    public function describe(array $bundle): array
    {
        $id = (string) ($bundle['model_id'] ?? '');
        $index = is_array($bundle['index'] ?? null) ? $bundle['index'] : [];
        $content = is_array($bundle['content'] ?? null) ? $bundle['content'] : [];
        $document = is_array($bundle['openapi'] ?? null) ? $bundle['openapi'] : [];
        $info = is_array($document['info'] ?? null) ? $document['info'] : [];
        $item = $document['components']['schemas']['RequestBody']['items'] ?? [];
        $taskType = is_string($item['properties']['taskType']['const'] ?? null) ? $item['properties']['taskType']['const'] : null;
        $capabilities = [];
        foreach ([$index['capabilities'] ?? [], $info['x-capabilities'] ?? [], $content['capabilities'] ?? []] as $list) {
            foreach (is_array($list) ? $list : [] as $capability) {
                if (is_string($capability)) {
                    $capabilities[] = str_starts_with($capability, 'io:') ? substr($capability, 3) : $capability;
                }
            }
        }
        $capabilities = array_values(array_unique($capabilities));
        $statuses = array_map(static fn ($status): string => strtolower((string) $status), array_filter([$index['status'] ?? null, $info['x-status'] ?? null, $content['status'] ?? null], 'is_string'));
        $skip = match (true) {
            in_array('deprecated', $statuses, true) => 'deprecated',
            in_array('coming-soon', $statuses, true) => 'coming-soon',
            in_array('openai-compatible', $statuses, true), in_array('text-to-text', $capabilities, true),
            in_array($taskType, self::LANGUAGE_TASKS, true) => 'text-to-text',
            default => null,
        };
        $air = null;
        foreach ([$item['properties']['model']['const'] ?? null, $info['x-air-id'] ?? null, $index['air'] ?? null, $content['air'] ?? null] as $candidate) {
            if (is_string($candidate) && self::isAir($candidate)) {
                $air = $candidate;
                break;
            }
        }
        $title = is_string($info['title'] ?? null) ? preg_replace('/^Runware API\s*-\s*/i', '', $info['title']) : null;

        return [
            'model_id' => $id,
            'schema_url' => is_string($bundle['schema_url'] ?? null) ? $bundle['schema_url'] : 'https://runware.ai/docs/models/'.$id.'/schema.json',
            'skip' => $skip, 'air' => $air, 'task_type' => $taskType,
            'name' => self::text($content['name'] ?? $index['name'] ?? $title ?? null, 160) ?? $id,
            'description' => self::text($content['headline'] ?? $info['summary'] ?? null, 2000),
            'logo_url' => self::logo($info['x-creator']['logo'] ?? null) ?? self::logo($bundle['creator']['logo'] ?? null),
            'available' => $skip === null && ($statuses === [] || in_array('live', $statuses, true)),
            'capabilities' => $capabilities,
        ];
    }

    /**
     * @return array<string, mixed> describe() facts plus output_kind, category, pricing and operations
     *                              (operation value => capability, provider_bindings, report, publishable, examples)
     */
    public function normalize(array $bundle, string $publicModelId): array
    {
        $model = [...$this->describe($bundle), 'output_kind' => null, 'pricing' => null, 'operations' => []];
        $model['category'] = self::category(null, $model['capabilities']);
        if ($model['skip'] !== null) {
            return $model;
        }
        $fallback = self::fallbackOperation($model['capabilities'], $model['task_type']);
        $document = is_array($bundle['openapi'] ?? null) ? $bundle['openapi'] : null;
        if ($document === null) {
            $model['operations'][$fallback->value] = $this->failure($fallback, [is_string($bundle['fetch_error'] ?? null)
                ? $bundle['fetch_error'] : 'The public Runware schema for this model is unavailable. Discover it again.']);

            return $model;
        }
        try {
            $request = $document['components']['schemas']['RequestBody']['items'] ?? null;
            $response = $document['components']['schemas']['ResponseBody']['properties']['data']['items'] ?? null;
            if (! is_array($request) || ! is_array($request['properties'] ?? null) || ! is_array($response)) {
                throw new InvalidArgumentException('The Runware schema does not publish a task request and result item.');
            }
            $request = self::strip(MediaJsonSchema::resolve($request, $document));
            $response = self::strip(MediaJsonSchema::resolve($response, $document));
            if (! is_array($request) || ! is_array($response)) {
                throw new InvalidArgumentException('The Runware schema forbids every request or result.');
            }
            $model['pricing'] = RunwarePriceReference::build($document, is_array($bundle['content'] ?? null) ? $bundle['content'] : null, $request);
            $taskType = $model['task_type'];
            $output = self::outputKind($taskType, $response);
            if ($taskType === null || $output === null) {
                throw new InvalidArgumentException('Runware task type '.($taskType ?? 'unknown').' is not supported by the media workspace.');
            }
            $model['output_kind'] = $output->value;
            $model['category'] = self::category($output, $model['capabilities']);
            $model['operations'] = $this->operations($bundle, $model, $document, $request, $response, $output, $publicModelId);
        } catch (Throwable $exception) {
            $model['operations'] = [$fallback->value => $this->failure($fallback, [$exception instanceof InvalidArgumentException
                ? $exception->getMessage() : 'The published Runware schema is malformed: '.class_basename($exception)])];
        }

        return $model;
    }

    /** @return array<string, array<string, mixed>> */
    private function operations(array $bundle, array $model, array $document, array $request, array $response, OutputKind $output, string $publicModelId): array
    {
        $blockers = [];
        if ($model['air'] === null) {
            $blockers[] = 'This Runware schema describes an architecture family without a fixed model identifier (AIR). Import a specific model instead.';
        }
        $declared = $request['properties'];
        if (! isset($declared['deliveryMethod']) || ! self::accepts($declared['deliveryMethod'], 'async')) {
            $blockers[] = 'This Runware model does not accept asynchronous delivery, which the media workspace requires.';
        }
        if (isset($declared['outputType']) && ! self::accepts($declared['outputType'], 'URL')) {
            $blockers[] = 'This Runware model cannot return downloadable output URLs.';
        }
        $limits = [];
        foreach ((array) ($request['x-constraints'] ?? []) as $constraint) {
            if (is_array($constraint) && ($constraint['operation'] ?? null) === 'fileSize' && is_int($constraint['maximum'] ?? null)) {
                foreach ((array) ($constraint['parameters'] ?? []) as $parameter) {
                    if (is_string($parameter)) {
                        $limits[$parameter] = $constraint['maximum'];
                    }
                }
            }
        }
        $properties = array_diff_key($declared, array_flip(self::TRANSPORT_FIELDS));
        foreach ($properties as $name => $property) {
            $properties[$name] = self::annotateAssets($property, [(string) $name], $limits, $output);
        }
        $required = array_values(array_intersect((array) ($request['required'] ?? []), array_keys($properties)));
        $member = ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties, ...($required !== [] ? ['required' => $required] : [])];
        $inputs = is_array($properties['inputs']['properties'] ?? null) ? $properties['inputs']['properties'] : [];
        // Mode classification uses each input's primary media kind (referenceImages[].audio is still an image reference).
        $kinds = [];
        foreach ($inputs as $name => $schema) {
            $kinds[$name] = self::inputKinds((string) $name, self::assetKinds($schema));
        }
        $speech = in_array('speech', (array) ($request['required'] ?? []), true);
        $modes = is_array($document['info']['x-modes'] ?? null) && $document['info']['x-modes'] !== []
            ? $document['info']['x-modes'] : [['id' => 'default', 'inputs' => array_values(array_filter((array) ($properties['inputs']['required'] ?? []), 'is_string'))]];
        $grouped = [];
        $warnings = [];
        foreach ($modes as $mode) {
            $names = is_array($mode) ? array_values(array_unique(array_filter((array) ($mode['inputs'] ?? []), 'is_string'))) : [];
            $label = is_array($mode) && is_string($mode['id'] ?? null) ? $mode['id'] : implode('+', $names);
            if (array_intersect($names, self::STATE_INPUTS) !== []) {
                $warnings[] = "Mode {$label} reuses provider-side state shared by the whole account and is not offered.";

                continue;
            }
            if (array_diff($names, array_keys($inputs)) !== []) {
                $warnings[] = "Mode {$label} names an undeclared input and is not offered.";

                continue;
            }
            $operation = self::modeOperation($output, array_values(array_unique(array_merge([], ...array_map(static fn ($name) => $kinds[$name], $names)))), $speech);
            if ($operation === null) {
                $warnings[] = "Mode {$label} produces text from text and is not offered.";

                continue;
            }
            $grouped[$operation->value]['modes'][$label] = $names;
        }
        if ($grouped === []) {
            throw new InvalidArgumentException('No Runware input mode of this model maps to a supported workspace operation.');
        }
        $constants = array_intersect_key(['deliveryMethod' => 'async', 'outputType' => 'URL', 'includeCost' => true], $declared);
        $injected = ['taskType', 'taskUUID', 'model', 'deliveryMethod', ...array_keys(array_intersect_key(['outputType' => true, 'includeCost' => true], $declared))];
        $annotated = self::annotateOutput($response, (string) $model['task_type']);
        $result = [];
        foreach ($grouped as $value => $group) {
            $operation = MediaOperation::from($value);
            $bindings = [
                'adapter' => 'runware_v1', 'endpoint' => $model['air'], 'task_type' => $model['task_type'], 'transport' => 'async',
                'request_schema' => $request, 'output_schema' => $annotated, 'constants' => $constants,
                'quantity_input' => null, 'source' => ['model_id' => $model['model_id'], 'schema_url' => $model['schema_url']],
            ];
            $opBlockers = $blockers;
            try {
                [$schema, $opBlockers] = $this->operationSchema($member, $request, $group['modes'], $injected, $model['pricing']['catalog_unit'] ?? 'generation', $opBlockers);
                MediaJsonSchema::errors($schema, null);
                $bindings['quantity_input'] = isset($schema['properties']['numberResults']) ? 'numberResults' : null;
                $capability = new MediaCapability($publicModelId, $operation, $output, 2, providerBindings: $bindings,
                    inputSchema: $schema, outputSchema: self::memberOutput($annotated));
                $examples = $this->examples($bundle, $operation, $schema, $kinds, $output, $speech);
            } catch (Throwable $exception) {
                $capability = null;
                $examples = [];
                $opBlockers[] = $exception instanceof InvalidArgumentException ? $exception->getMessage()
                    : 'The published Runware schema is malformed: '.class_basename($exception);
            }
            $result[$value] = $this->result($capability, $operation, $bindings, $opBlockers, $warnings, array_keys($group['modes']), $examples);
        }

        return $result;
    }

    /**
     * The member contract for one operation: its inputs, the provider rules that still apply
     * to those inputs, billable duration and result count, and playground groups.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function operationSchema(array $member, array $request, array $modes, array $injected, string $unit, array $blockers): array
    {
        $schema = $member;
        $allowed = array_values(array_unique(array_merge([], ...array_values($modes))));
        // Inputs present in every documented mode of this operation are required.
        $shared = array_values(count($modes) === 1 ? reset($modes) : array_intersect(...array_values($modes)));
        $textual = in_array([], $modes, true);
        if ($allowed === []) {
            if (in_array('inputs', (array) ($schema['required'] ?? []), true)) {
                throw new InvalidArgumentException('The provider requires input files for every request, but this mode sends none.');
            }
            unset($schema['properties']['inputs']);
        } else {
            $inputs = $schema['properties']['inputs'];
            $inputs['properties'] = array_intersect_key($inputs['properties'], array_flip($allowed));
            $sourceRequired = array_values(array_filter((array) ($inputs['required'] ?? []), 'is_string'));
            if (array_diff($sourceRequired, $allowed) !== []) {
                throw new InvalidArgumentException('The provider requires an input file this mode does not accept.');
            }
            $inputRequired = array_values(array_unique([...$sourceRequired, ...$shared]));
            $inputs['additionalProperties'] = false;
            unset($inputs['required']);
            if ($inputRequired !== []) {
                $inputs['required'] = $inputRequired;
            }
            $schema['properties']['inputs'] = $inputs;
            if (! $textual) {
                $schema['required'] = array_values(array_unique([...(array) ($schema['required'] ?? []), 'inputs']));
            }
        }
        if (isset($schema['properties']['numberResults']) && is_array($schema['properties']['numberResults'])) {
            $schema['properties']['numberResults'] = self::clampResults($schema['properties']['numberResults']);
        }
        $metered = null;
        if ($unit === 'second') {
            $duration = $schema['properties']['duration'] ?? null;
            $billable = is_array($duration) ? self::billableDuration($duration) : null;
            if ($billable === null) {
                $blockers[] = is_array($duration)
                    ? 'Runware bills this model per second of output, but this operation\'s duration is not a whole number of seconds, so it cannot be priced per second.'
                    : 'Runware bills this model per second of output, but this operation has no duration input to meter, so it cannot be priced per second.';
            } else {
                // Per-second billing meters the whole seconds the member explicitly requests.
                $metered = $schema;
                $metered['properties']['duration'] = $billable;
                $metered['required'] = array_values(array_unique([...(array) ($metered['required'] ?? []), 'duration']));
            }
        }
        $rules = array_values(array_filter((array) ($request['allOf'] ?? []), static fn ($rule) => is_array($rule) || is_bool($rule)));
        $loose = array_intersect_key($request, array_flip(['anyOf', 'oneOf', 'not', 'if', 'then', 'else', 'dependentRequired', 'dependentSchemas']));
        if ($loose !== []) {
            $rules[] = $loose;
        }
        try {
            [$schema, $rules] = self::applyRules($metered ?? $schema, $rules, $injected);
        } catch (InvalidArgumentException $exception) {
            if ($metered === null) {
                throw $exception;
            }
            // The provider forbids an explicit duration here (the output length follows an input).
            $blockers[] = 'Runware bills this model per second of output, but this operation does not accept an explicit duration to meter, so it cannot be priced per second.';
            [$schema, $rules] = self::applyRules($schema, $rules, $injected);
        }
        if (array_intersect(array_merge([], ...array_map(RunwareRuleSimplifier::mentionedRootMembers(...), $rules)), self::TRANSPORT_FIELDS) !== []) {
            $blockers[] = 'A provider rule depends on a transport field the workspace controls, so member validation cannot mirror it.';
        }
        $references = [];
        foreach ($rules as $rule) {
            array_push($references, ...RunwareRuleSimplifier::presenceReferences($rule));
        }
        array_push($references, ...self::nestedPresenceReferences($schema, []));
        foreach ($references as $path) {
            // Filling a default would satisfy a presence test the member never chose.
            $schema = self::withoutDefault($schema, $path);
        }
        $minimal = [];
        foreach ($modes as $names) {
            $minimal[] = $names;
        }
        $minimal = array_values(array_filter($minimal, static function (array $names) use ($minimal): bool {
            foreach ($minimal as $other) {
                if ($other !== $names && array_diff($other, $names) === [] && count($other) < count($names)) {
                    return false;
                }
            }

            return true;
        }));
        $minimal = array_values(array_unique(array_map(static function (array $names): array {
            sort($names);

            return $names;
        }, $minimal), SORT_REGULAR));
        if (! $textual && count($minimal) > 1) {
            // At least one of this operation's documented input combinations must be supplied.
            $rules[] = ['anyOf' => array_map(static fn (array $names): array => ['required' => ['inputs'], 'properties' => ['inputs' => ['required' => $names]]], $minimal)];
        }
        foreach ($schema['properties'] as $name => $property) {
            if (is_array($property)) {
                $schema['properties'][$name]['x-workspace-group'] = self::GROUPS[$name] ?? 'core';
            }
        }
        if (($schema['required'] ?? null) === []) {
            unset($schema['required']);
        }
        if ($rules !== []) {
            $schema['allOf'] = array_values($rules);
        }

        return [$schema, $blockers];
    }

    /** @return array{0: array<string, mixed>, 1: list<array|bool>} */
    private static function applyRules(array $schema, array $rules, array $injected): array
    {
        for ($pass = 0; $pass < 12; $pass++) {
            $simplifier = new RunwareRuleSimplifier($schema, $injected);
            $changed = false;
            $kept = [];
            foreach ($rules as $rule) {
                $simplified = $simplifier->simplify($rule);
                if ($simplified === true) {
                    continue;
                }
                if ($simplified === false) {
                    throw new InvalidArgumentException('The provider rules reject every request for this operation.');
                }
                $present = RunwareRuleSimplifier::requiredPaths($simplified);
                if ($present !== null && $present !== []) {
                    foreach ($present as $path) {
                        $schema = self::requirePath($schema, $path, $injected);
                    }
                    $changed = true;

                    continue;
                }
                $absent = RunwareRuleSimplifier::forbiddenPaths($simplified);
                if ($absent !== null) {
                    $closed = true;
                    foreach ($absent as $path) {
                        [$schema, $removed] = self::forbid($schema, $path);
                        $closed = $closed && $removed;
                    }
                    $changed = true;
                    if (! $closed) {
                        $kept[] = $simplified;
                    }

                    continue;
                }
                $kept[] = $simplified;
            }
            $rules = $kept;
            if (! $changed) {
                break;
            }
        }

        return [$schema, $rules];
    }

    private static function requirePath(array $schema, array $path, array $injected): array
    {
        $name = (string) $path[0];
        if (count($path) === 1 && in_array($name, $injected, true)) {
            return $schema;
        }
        $property = $schema['properties'][$name] ?? null;
        if (! is_array($property) && $property !== true) {
            throw new InvalidArgumentException('The provider rules require a member this operation cannot send.');
        }
        $schema['required'] = array_values(array_unique([...(array) ($schema['required'] ?? []), $name]));
        if (count($path) > 1) {
            if (! is_array($property)) {
                throw new InvalidArgumentException('The provider rules require a member this operation cannot send.');
            }
            $schema['properties'][$name] = self::requirePath($property, array_slice($path, 1), []);
        }

        return $schema;
    }

    /** @return array{0: array<string, mixed>, 1: bool} the schema and whether removal alone forbids the member */
    private static function forbid(array $schema, array $path): array
    {
        // A forbidden member that its parent requires makes the parent itself impossible.
        while (count($path) > 1 && in_array(end($path), (array) (self::nodeAt($schema, array_slice($path, 0, -1))['required'] ?? []), true)) {
            $path = array_slice($path, 0, -1);
        }
        if (count($path) === 1 && in_array($path[0], (array) ($schema['required'] ?? []), true)) {
            throw new InvalidArgumentException('The provider rules forbid a member this operation requires.');
        }

        [$schema, $closed] = self::unsetPath($schema, $path);
        $parent = array_slice($path, 0, -1);
        $node = $parent === [] ? null : self::nodeAt($schema, $parent);
        if ($node !== null && ! isset($node['properties']) && ($node['additionalProperties'] ?? true) === false && ($node['required'] ?? []) === []) {
            // A closed object with nothing left to send is itself pointless for this operation.
            [$schema] = self::forbid($schema, $parent);
        }

        return [$schema, $closed];
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    private static function unsetPath(array $schema, array $path): array
    {
        $name = (string) $path[0];
        if (count($path) === 1) {
            unset($schema['properties'][$name]);
            if (($schema['properties'] ?? null) === []) {
                unset($schema['properties']);
            }

            return [$schema, ($schema['additionalProperties'] ?? true) === false];
        }
        if (! is_array($schema['properties'][$name] ?? null)) {
            return [$schema, true];
        }
        [$child, $closed] = self::unsetPath($schema['properties'][$name], array_slice($path, 1));
        $schema['properties'][$name] = $child;

        return [$schema, $closed];
    }

    private static function nodeAt(array $schema, array $path): ?array
    {
        foreach ($path as $segment) {
            $schema = $schema['properties'][$segment] ?? null;
            if (! is_array($schema)) {
                return null;
            }
        }

        return $schema;
    }

    private static function withoutDefault(array $schema, array $path): array
    {
        $name = (string) $path[0];
        if (! is_array($schema['properties'][$name] ?? null)) {
            return $schema;
        }
        if (count($path) === 1) {
            unset($schema['properties'][$name]['default']);
        } else {
            $schema['properties'][$name] = self::withoutDefault($schema['properties'][$name], array_slice($path, 1));
        }

        return $schema;
    }

    /** Presence tests inside member objects (e.g. acceleratorOptions rules), relative to the object. */
    private static function nestedPresenceReferences(array $schema, array $path): array
    {
        $paths = [];
        foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $name => $child) {
            if (! is_array($child)) {
                continue;
            }
            $rules = array_intersect_key($child, array_flip(['allOf', 'anyOf', 'oneOf', 'not', 'if', 'then', 'else', 'dependentRequired', 'dependentSchemas']));
            if ($rules !== [] && isset($child['properties'])) {
                array_push($paths, ...RunwareRuleSimplifier::presenceReferences($rules, [...$path, (string) $name]));
            }
            array_push($paths, ...self::nestedPresenceReferences($child, [...$path, (string) $name]));
        }

        return $paths;
    }

    /** Result count stays a member choice, bounded by what one whole reservation can safely cover. */
    private static function clampResults(array $schema): array
    {
        // Never above MAX_RESULTS, even when the provider's own minimum is higher; billing counts whole results.
        $minimum = is_int($schema['minimum'] ?? null) ? min(self::MAX_RESULTS, max(1, $schema['minimum'])) : 1;
        $maximum = is_int($schema['maximum'] ?? null) ? min($schema['maximum'], self::MAX_RESULTS) : self::MAX_RESULTS;
        $maximum = max($minimum, $maximum);
        $schema['type'] = 'integer';
        $schema['minimum'] = $minimum;
        $schema['maximum'] = $maximum;
        if (is_int($schema['default'] ?? null)) {
            $schema['default'] = max($minimum, min($maximum, $schema['default']));
        }

        return $schema;
    }

    /** A duration the per-second tariff can meter: whole seconds only, never "auto". */
    private static function billableDuration(array $schema): ?array
    {
        $annotations = array_intersect_key($schema, array_flip(['title', 'description', 'examples']));
        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (! isset($schema[$keyword])) {
                continue;
            }
            $numeric = array_values(array_filter((array) $schema[$keyword], static fn ($branch) => is_array($branch)
                && array_intersect((array) ($branch['type'] ?? []), ['integer', 'number']) !== []));
            if (count($numeric) !== 1) {
                return null;
            }
            $schema = [...$annotations, ...$numeric[0], ...(array_key_exists('default', $schema) ? ['default' => $schema['default']] : [])];
        }
        $types = (array) ($schema['type'] ?? []);
        if (in_array('integer', $types, true)) {
            $schema['type'] = 'integer';
        } elseif (in_array('number', $types, true)) {
            $schema['type'] = 'integer';
            if (is_numeric($schema['minimum'] ?? null)) {
                $schema['minimum'] = (int) ceil((float) $schema['minimum']);
            }
            if (is_numeric($schema['maximum'] ?? null)) {
                $schema['maximum'] = (int) floor((float) $schema['maximum']);
            }
            if (isset($schema['multipleOf']) && (! is_numeric($schema['multipleOf']) || floor((float) $schema['multipleOf']) !== (float) $schema['multipleOf'])) {
                unset($schema['multipleOf']);
            }
        } else {
            return null;
        }
        if (isset($schema['enum'])) {
            $schema['enum'] = array_values(array_filter((array) $schema['enum'], static fn ($value) => is_int($value) && $value >= 1));
            if ($schema['enum'] === []) {
                return null;
            }
        }
        $minimum = max(1, is_numeric($schema['minimum'] ?? null) ? (int) $schema['minimum'] : 1);
        $schema['minimum'] = $minimum;
        if (is_numeric($schema['maximum'] ?? null) && (int) $schema['maximum'] < $minimum) {
            return null;
        }
        $default = $schema['default'] ?? null;
        unset($schema['default']);
        if ((is_int($default) || (is_float($default) && floor($default) === $default)) && $default >= $minimum
            && (! is_numeric($schema['maximum'] ?? null) || $default <= $schema['maximum'])
            && (! isset($schema['enum']) || in_array((int) $default, $schema['enum'], true))) {
            $schema['default'] = (int) $default;
        }
        unset($schema['exclusiveMinimum'], $schema['exclusiveMaximum']);

        return $schema;
    }

    /** Examples as form values: text and scalar members only, each validated against the operation. */
    private function examples(array $bundle, MediaOperation $operation, array $schema, array $kinds, OutputKind $output, bool $speech): array
    {
        $examples = $bundle['examples']['examples'] ?? $bundle['examples'] ?? [];
        $result = [];
        foreach (is_array($examples) ? array_slice($examples, 0, 60) : [] as $example) {
            if (count($result) >= 6) {
                break;
            }
            $request = is_array($example) ? ($example['request'] ?? null) : null;
            $title = is_array($example) ? self::text($example['title'] ?? null, 160) : null;
            if (! is_array($request) || $title === null) {
                continue;
            }
            $values = array_diff_key($request, array_flip(self::TRANSPORT_FIELDS));
            $inputs = is_array($values['inputs'] ?? null) ? array_keys($values['inputs']) : [];
            $allowed = array_keys($schema['properties']['inputs']['properties'] ?? []);
            if (array_diff($inputs, $allowed) !== []
                || self::modeOperation($output, array_values(array_unique(array_merge([], ...array_map(static fn ($name) => $kinds[$name] ?? [], $inputs)))), $speech) !== $operation) {
                continue;
            }
            try {
                MediaJsonSchema::normalize($schema, $values);
            } catch (Throwable) {
                continue;
            }
            $member = [];
            foreach ($values as $name => $value) {
                if ($name !== 'inputs' && isset($schema['properties'][$name]) && ! self::hasAsset($schema['properties'][$name])) {
                    $member[$name] = $value;
                }
            }
            $prompt = $values['positivePrompt'] ?? $values['prompt'] ?? $values['speech']['text'] ?? null;
            $result[] = ['title' => $title, 'prompt' => is_string($prompt) ? mb_substr($prompt, 0, 4000) : null, 'values' => $member];
        }

        return $result;
    }

    private static function modeOperation(OutputKind $output, array $kinds, bool $speech): ?MediaOperation
    {
        $has = static fn (string $kind): bool => in_array($kind, $kinds, true);

        return match ($output) {
            OutputKind::Image => $kinds === [] ? MediaOperation::TextToImage : MediaOperation::ImageEdit,
            OutputKind::Video => $has('video') ? MediaOperation::VideoToVideo
                : ($has('audio') ? MediaOperation::AudioToVideo : ($has('image') ? MediaOperation::ImageToVideo : MediaOperation::TextToVideo)),
            OutputKind::Audio => $has('video') ? MediaOperation::VideoToAudio
                : ($speech ? MediaOperation::TextToSpeech : ($has('audio') ? MediaOperation::AudioToAudio : MediaOperation::TextToAudio)),
            OutputKind::Model3d => $has('image') ? MediaOperation::ImageTo3d : MediaOperation::TextTo3d,
            OutputKind::Data => $has('image') ? MediaOperation::ImageToText
                : ($has('video') ? MediaOperation::VideoToText : ($has('audio') ? MediaOperation::AudioToText : null)),
            default => null,
        };
    }

    private static function outputKind(?string $taskType, array $response): ?OutputKind
    {
        if ($taskType === null) {
            return null;
        }
        if (in_array($taskType, ['upscale', 'removeBackground'], true)) {
            return isset($response['properties']['videoURL']) ? OutputKind::Video : OutputKind::Image;
        }

        return self::OUTPUTS[$taskType] ?? null;
    }

    private static function category(?OutputKind $output, array $capabilities): string
    {
        if ($output === null) {
            foreach ($capabilities as $capability) {
                if (preg_match('/-to-(image|video|audio|3d|text)$/', $capability, $match) === 1) {
                    $output = match ($match[1]) {
                        'image' => OutputKind::Image, 'video' => OutputKind::Video, 'audio' => OutputKind::Audio,
                        '3d' => OutputKind::Model3d, default => OutputKind::Data,
                    };
                    break;
                }
            }
        }

        return match ($output) {
            OutputKind::Image => 'image', OutputKind::Video => 'video', OutputKind::Audio => 'audio',
            OutputKind::Model3d => 'model3d', default => 'other',
        };
    }

    private static function fallbackOperation(array $capabilities, ?string $taskType): MediaOperation
    {
        if ($taskType === 'training') {
            return MediaOperation::Training;
        }
        foreach (self::CAPABILITY_OPERATIONS as $capability => $operation) {
            if (in_array($capability, $capabilities, true)) {
                return $operation;
            }
        }

        return MediaOperation::Inference;
    }

    /** Owned uploads (UUID) or public HTTPS links replace Runware's UUID/URL/data-URI/base64 alternatives. */
    private static function annotateAssets(array|bool $schema, array $path, array $limits, OutputKind $output): array|bool
    {
        if (! is_array($schema)) {
            return $schema;
        }
        if (self::isAssetString($schema)) {
            $names = array_values(array_filter($path, static fn ($segment) => $segment !== '[]'));
            $kind = self::assetKind($schema, $names);
            $maximum = $limits[implode('.', $names)] ?? null;

            return [...array_intersect_key($schema, array_flip(['title', 'description', 'deprecated'])),
                'type' => 'string', 'minLength' => 1, 'maxLength' => 2048,
                'x-workspace-asset' => ['kind' => $kind, 'role' => self::assetRole($kind, $names, $output)->value,
                    ...(is_int($maximum) ? ['max_file_size' => $maximum] : []), 'accepts_url' => true]];
        }
        foreach (['properties', 'patternProperties'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $name => $child) {
                $schema[$keyword][$name] = self::annotateAssets($child, [...$path, (string) $name], $limits, $output);
            }
        }
        if (isset($schema['items']) && (is_array($schema['items']) || is_bool($schema['items']))) {
            $schema['items'] = self::annotateAssets($schema['items'], [...$path, '[]'], $limits, $output);
        }
        foreach (is_array($schema['prefixItems'] ?? null) ? $schema['prefixItems'] : [] as $index => $child) {
            $schema['prefixItems'][$index] = self::annotateAssets($child, [...$path, '[]'], $limits, $output);
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $index => $child) {
                $schema[$keyword][$index] = self::annotateAssets($child, $path, $limits, $output);
            }
        }

        return $schema;
    }

    private static function isAssetString(array $schema): bool
    {
        if (! in_array($schema['type'] ?? 'string', ['string'], true)) {
            return false;
        }
        $alternatives = array_values(array_filter([...(array) ($schema['anyOf'] ?? []), ...(array) ($schema['oneOf'] ?? [])], 'is_array'));
        $formats = array_column($alternatives, 'format');

        return in_array('uri', $formats, true)
            && (in_array('uuid', $formats, true) || str_contains(json_encode($alternatives, JSON_UNESCAPED_SLASHES) ?: '', 'data:'));
    }

    private static function assetKind(array $schema, array $names): string
    {
        $patterns = str_replace('\\/', '/', (string) json_encode([...(array) ($schema['anyOf'] ?? []), ...(array) ($schema['oneOf'] ?? [])], JSON_UNESCAPED_SLASHES));
        if (preg_match('~\^data:(image|video|audio)~', $patterns, $match) === 1) {
            return $match[1];
        }
        foreach ([strtolower(($schema['title'] ?? '').' '.($schema['description'] ?? '')), strtolower(implode(' ', $names))] as $text) {
            $best = null;
            $position = PHP_INT_MAX;
            foreach (self::KIND_WORDS as $kind => $words) {
                foreach ($words as $word) {
                    $found = strpos($text, $word);
                    if ($found !== false && $found < $position) {
                        [$best, $position] = [$kind, $found];
                    }
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        return 'file';
    }

    private static function assetRole(string $kind, array $names, OutputKind $output): InputRole
    {
        $lower = array_map('strtolower', $names);
        $leaf = (string) end($lower);
        $reference = array_filter($lower, static fn (string $name): bool => str_contains($name, 'reference')) !== [];

        return match ($kind) {
            'image' => str_contains($leaf, 'mask') ? InputRole::MaskImage : (in_array('frameimages', $lower, true) ? InputRole::InitFrame : InputRole::ImageRef),
            'video' => InputRole::ReferenceVideo,
            // A single driving track (lip-sync, avatar) is speech; reference clips only steer the voice or style.
            'audio' => $output === OutputKind::Video && ! $reference && (in_array('audio', $lower, true) || in_array('segments', $lower, true))
                ? InputRole::SpeechAudio : InputRole::AudioReference,
            'model3d' => InputRole::ModelReference,
            'document' => InputRole::Document,
            default => InputRole::GenericFile,
        };
    }

    /** @return list<string> asset kinds declared anywhere in an input's schema */
    private static function assetKinds(mixed $schema): array
    {
        if (! is_array($schema)) {
            return [];
        }
        $kinds = is_string($schema['x-workspace-asset']['kind'] ?? null) ? [$schema['x-workspace-asset']['kind']] : [];
        foreach ($schema as $key => $child) {
            if ($key !== 'x-workspace-asset' && is_array($child)) {
                array_push($kinds, ...self::assetKinds($child));
            }
        }

        return array_values(array_unique($kinds));
    }

    /** An input carrying several media kinds counts as the one its name declares (e.g. referenceImages). */
    private static function inputKinds(string $name, array $kinds): array
    {
        if (count($kinds) < 2) {
            return $kinds;
        }
        $named = array_values(array_filter(['image', 'video', 'audio'], static fn (string $kind): bool => in_array($kind, $kinds, true)
            && str_contains(strtolower($name), $kind)));

        return count($named) === 1 ? $named : $kinds;
    }

    private static function hasAsset(mixed $schema): bool
    {
        return self::assetKinds($schema) !== [];
    }

    /** Annotate downloadable result members; the complete item (with cost) stays in the binding. */
    private static function annotateOutput(array $item, string $taskType): array
    {
        foreach (is_array($item['properties'] ?? null) ? $item['properties'] : [] as $name => $schema) {
            if (is_array($schema) && preg_match('/^(image|video|audio|guideImage|maskImage)(URL|DataURI|Base64Data)$/D', (string) $name, $match) === 1) {
                $item['properties'][$name]['x-workspace-output'] = ['kind' => match ($match[1]) { 'video' => 'video', 'audio' => 'audio', default => 'image' },
                    ...($match[2] === 'Base64Data' ? ['encoding' => 'base64'] : [])];
            }
        }
        foreach (is_array($item['properties']['outputs']['properties'] ?? null) ? $item['properties']['outputs']['properties'] : [] as $name => $schema) {
            if (is_array($schema['items']['properties']['url'] ?? null)) {
                $item['properties']['outputs']['properties'][$name]['items']['properties']['url']['x-workspace-output'] = [
                    'kind' => $name === 'files' ? ($taskType === '3dInference' ? 'model3d' : 'file') : 'image',
                ];
            }
        }

        return $item;
    }

    /** One result item as a member sees it: no provider cost, task identity or status. */
    private static function memberOutput(array $item): array
    {
        $item['properties'] = array_diff_key(is_array($item['properties'] ?? null) ? $item['properties'] : [], array_flip(self::PRIVATE_RESULT_FIELDS));
        if (isset($item['required'])) {
            $item['required'] = array_values(array_diff((array) $item['required'], self::PRIVATE_RESULT_FIELDS));
        }

        return $item;
    }

    private static function accepts(mixed $schema, string $value): bool
    {
        if (! is_array($schema)) {
            return $schema === true;
        }
        if (array_key_exists('const', $schema)) {
            return $schema['const'] === $value;
        }
        if (isset($schema['enum'])) {
            return in_array($value, (array) $schema['enum'], true);
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (isset($schema[$keyword])) {
                foreach ((array) $schema[$keyword] as $branch) {
                    if ($branch === $value || (is_array($branch) && self::accepts($branch, $value))) {
                        return true;
                    }
                }

                return false;
            }
        }

        return true;
    }

    /** Identifiers, dialect markers and unused definitions carry no validation once references are expanded. */
    private static function strip(array|bool $schema): array|bool
    {
        if (! is_array($schema)) {
            return $schema;
        }
        unset($schema['$id'], $schema['$schema'], $schema['$defs'], $schema['definitions']);
        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = self::strip($value);
            }
        }

        return $schema;
    }

    public static function isAir(string $value): bool
    {
        return preg_match('/^'.self::AIR_PATTERN.'$/D', $value) === 1;
    }

    private static function logo(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        $url = trim($url);

        return str_starts_with($url, 'https://') && strlen($url) <= 255 && filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('/[\x00-\x20\x7f\\\\"<>]/', $url) !== 1 ? $url : null;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function failure(MediaOperation $operation, array $blockers): array
    {
        return $this->result(null, $operation, [], $blockers, [], [], []);
    }

    private function result(?MediaCapability $capability, MediaOperation $operation, array $bindings, array $blockers, array $warnings, array $modes, array $examples): array
    {
        $blockers = array_values(array_unique($blockers));
        $compatible = $capability !== null && $blockers === [];

        return [
            'capability' => $capability, 'operation' => $operation->value, 'provider_bindings' => $bindings,
            'publishable' => $compatible, 'examples' => $compatible ? $examples : [],
            'report' => ['version' => 2, 'normalizer_version' => self::VERSION, 'compatible' => $compatible,
                'blockers' => $blockers, 'warnings' => array_values(array_unique($warnings)), 'modes' => $modes,
                'test_kind' => 'offline_contract', 'live_verified' => false],
        ];
    }
}
