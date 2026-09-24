<?php

namespace App\Media;

use App\Media\Exceptions\CapabilityValidationException;
use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use stdClass;

/** JSON Schema semantics shared by admission, typed forms and owned-file substitution. */
final class MediaJsonSchema
{
    private const CHILDREN = ['items', 'additionalItems', 'additionalProperties', 'contains', 'not', 'if', 'then', 'else', 'propertyNames', 'unevaluatedItems', 'unevaluatedProperties'];
    private const LISTS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];
    private const MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    /** Expand only local references. Provider schemas never cause network or PHP execution. */
    public static function resolve(array|bool $schema, array $document): array|bool
    {
        $nodes = 0;

        return self::expand($schema, $document, [], 0, $nodes);
    }

    private static function expand(array|bool $schema, array $document, array $seen, int $depth, int &$nodes): array|bool
    {
        if (++$nodes > 25000 || $depth > 64) {
            throw new InvalidArgumentException('The provider schema exceeds the supported structural safety limit.');
        }
        if (is_bool($schema)) {
            return $schema;
        }
        if (isset($schema['$ref'])) {
            $ref = $schema['$ref'];
            if (! is_string($ref) || ! str_starts_with($ref, '#/') || isset($seen[$ref])) {
                throw new InvalidArgumentException('The provider schema contains an external or recursive reference requiring an explicit contract.');
            }
            $target = $document;
            foreach (explode('/', substr($ref, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (! is_array($target) || ! array_key_exists($segment, $target)) {
                    throw new InvalidArgumentException('The provider schema contains an unresolved local reference.');
                }
                $target = $target[$segment];
            }
            if (! is_array($target) && ! is_bool($target)) {
                throw new InvalidArgumentException('A provider reference does not identify a JSON Schema.');
            }
            $resolved = self::expand($target, $document, [...$seen, $ref => true], $depth + 1, $nodes);
            unset($schema['$ref']);
            if ($schema === []) {
                return $resolved;
            }
            $annotations = array_intersect_key($schema, array_flip(['title', 'description', 'default', 'examples', 'deprecated', '_fal_ui_field', 'ui', 'max_file_size', 'max_pixels']));
            $constraints = array_diff_key($schema, $annotations);
            $base = is_array($resolved) ? array_replace($resolved, $annotations) : ['allOf' => [$resolved], ...$annotations];
            if ($constraints !== []) {
                $base = [...$annotations, 'allOf' => [$base, self::expand($constraints, $document, $seen, $depth + 1, $nodes)]];
            }

            return $base;
        }
        if (isset($schema['items']) && is_array($schema['items']) && $schema['items'] !== [] && array_is_list($schema['items'])) {
            // Draft-4 tuple form: positional schemas become prefixItems, additionalItems governs the rest.
            $schema['prefixItems'] = $schema['items'];
            unset($schema['items']);
            if (array_key_exists('additionalItems', $schema)) {
                $schema['items'] = $schema['additionalItems'];
                unset($schema['additionalItems']);
            }
        }
        foreach (self::MAPS as $keyword) {
            if (! isset($schema[$keyword]) || ! is_array($schema[$keyword])) {
                continue;
            }
            foreach ($schema[$keyword] as $name => $child) {
                if (! is_array($child) && ! is_bool($child)) {
                    throw new InvalidArgumentException('A provider property definition is not a JSON Schema.');
                }
                $schema[$keyword][$name] = self::expand($child, $document, $seen, $depth + 1, $nodes);
            }
        }
        foreach (self::LISTS as $keyword) {
            foreach ($schema[$keyword] ?? [] as $index => $child) {
                if (! is_array($child) && ! is_bool($child)) {
                    throw new InvalidArgumentException('A provider alternative is not a JSON Schema.');
                }
                $schema[$keyword][$index] = self::expand($child, $document, $seen, $depth + 1, $nodes);
            }
        }
        foreach (self::CHILDREN as $keyword) {
            if (isset($schema[$keyword]) && (is_array($schema[$keyword]) || is_bool($schema[$keyword]))) {
                $schema[$keyword] = self::expand($schema[$keyword], $document, $seen, $depth + 1, $nodes);
            }
        }
        if (($schema['nullable'] ?? false) === true) {
            unset($schema['nullable']);
            $schema = ['anyOf' => [$schema, ['type' => 'null']]];
        }

        return $schema;
    }

    public static function normalize(array $schema, array $values): array
    {
        self::assertBounded($values);
        $normalized = self::defaults($schema, $values);
        $errors = self::errors($schema, $normalized);
        if ($errors === []) {
            $errors = self::urlErrors($schema, $normalized);
        }
        if ($errors !== []) {
            throw new CapabilityValidationException($errors);
        }

        return is_array($normalized) ? $normalized : (array) $normalized;
    }

    /**
     * Member-supplied links are provider parameters: the provider fetches them, never this
     * server. Require public HTTPS without credentials so private or internal destinations and
     * secrets never enter a provider request.
     */
    public static function publicUrlError(string $url): ?string
    {
        if (strlen($url) > 2048) {
            return 'Use a URL of at most 2048 characters.';
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ($parts['host'] ?? '') === ''
            || filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('/[\x00-\x20\x7f\\\\]/', rawurldecode($url)) === 1) {
            return 'Use a public https:// URL or upload a file.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Remove the username or password from the URL.';
        }
        // Same destination rules as provider result URLs (GeneratedImageStore::validResultUrl): globally routable
        // addresses only, and no numeric/hex host shorthand such as 127.1 or 0x7f.0.0.1 posing as a hostname.
        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (($ip && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)
            || (! $ip && (! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                || preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/i', $host) === 1
                || preg_match('/(?:^|\.)(?:localhost|localdomain|local|internal|lan|home|intranet|corp|onion)$/', $host) === 1))) {
            return 'Use a public internet address, not a local or private one.';
        }

        return null;
    }

    public static function isAssetId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1;
    }

    /** @return array<string, string> */
    private static function urlErrors(array $schema, mixed $values): array
    {
        $errors = [];
        self::visit($schema, $values, [], static function (array $node, mixed $value, array $path) use (&$errors): void {
            if (! is_string($value)) {
                return;
            }
            $link = isset($node['x-workspace-asset'])
                ? ! self::isAssetId($value)
                : in_array($node['format'] ?? null, ['uri', 'url', 'iri'], true);
            $message = $link ? self::publicUrlError($value) : null;
            if ($message !== null) {
                $errors[$path === [] ? 'inputs' : implode('.', $path)] = $message;
            }
        });

        return $errors;
    }

    /** Keep the root request an array while restoring schema-declared JSON objects below it. */
    public static function objectMembers(array|bool $schema, array $data): array
    {
        $schema = is_array($schema) ? $schema : [];
        foreach ($data as $name => $value) {
            $data[$name] = self::dataObject($schema['properties'][$name] ?? $schema['additionalProperties'] ?? true, $value);
        }

        return $data;
    }

    /** Defaults apply to absent properties, never explicit null/false/zero or inactive alternatives. */
    private static function defaults(array|bool $schema, mixed $value): mixed
    {
        if (is_bool($schema)) {
            return $value;
        }
        foreach ($schema['allOf'] ?? [] as $branch) {
            $value = self::defaults($branch, $value);
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach ($schema[$keyword] ?? [] as $branch) {
                $candidate = self::defaults($branch, $value);
                if (self::errors($branch, $candidate) === []) {
                    $value = $candidate;
                    break;
                }
            }
        }
        if (isset($schema['if'])) {
            $branch = self::errors($schema['if'], $value) === [] ? ($schema['then'] ?? true) : ($schema['else'] ?? true);
            $value = self::defaults($branch, $value);
        }
        if (is_array($value) || $value instanceof stdClass) {
            // Past a declared size bound the value is rejected anyway; filling defaults inside it would only
            // multiply validator work (each oneOf/anyOf item) while the caller holds its row locks.
            $size = count((array) $value);
            if ((is_int($schema['maxItems'] ?? null) && is_array($value) && array_is_list($value) && $size > $schema['maxItems'])
                || (is_int($schema['maxProperties'] ?? null) && $size > $schema['maxProperties'])) {
                return $value;
            }
            if (($schema['type'] ?? null) === 'object' || isset($schema['properties'])) {
                $object = $value instanceof stdClass;
                $data = (array) $value;
                foreach ($schema['properties'] ?? [] as $name => $property) {
                    if (! array_key_exists($name, $data) && is_array($property) && array_key_exists('default', $property)) {
                        $data[$name] = $property['default'];
                    }
                    if (array_key_exists($name, $data)) {
                        $data[$name] = self::defaults($property, $data[$name]);
                    }
                }
                foreach ($data as $name => $item) {
                    if (! array_key_exists($name, $schema['properties'] ?? []) && is_array($schema['additionalProperties'] ?? null)) {
                        $data[$name] = self::defaults($schema['additionalProperties'], $item);
                    }
                }
                $value = $object ? (object) $data : $data;
            } elseif (is_array($value) && array_is_list($value)) {
                foreach ($value as $index => $item) {
                    $value[$index] = self::defaults($schema['prefixItems'][$index] ?? $schema['items'] ?? true, $item);
                }
            }
        }

        return $value;
    }

    /** Return consumer-facing field errors, without provider URLs or the submitted value. */
    public static function errors(array|bool $schema, mixed $value): array
    {
        $validator = new Validator(max_errors: 20, stop_at_first_error: false);
        $validator->setResolver(null);
        foreach (['allowFilters', 'allowMappers', 'allowTemplates', 'allowGlobals', 'allowDefaults', 'allowSlots', 'allowKeywordValidators', 'allowPragmas', 'allowDataKeyword', 'allowRelativeJsonPointerInRef'] as $option) {
            $validator->parser()->setOption($option, false);
        }
        $result = $validator->validate(self::dataObject($schema, $value), self::schemaObject($schema));
        if ($result->isValid()) {
            return [];
        }
        $formatter = new ErrorFormatter;
        $formatted = $formatter->format($result->error(), true, static function (ValidationError $error, ?string $message = null) use ($formatter): ?string {
            if ($error->keyword() !== 'additionalProperties' || ! is_array($error->args()['properties'] ?? null)) {
                return $formatter->formatErrorMessage($error, $message);
            }
            // Opis also lists declared siblings of an invalid property; only undeclared names are errors.
            $schema = $error->schema()->info()->data();
            $declared = is_object($schema) && is_object($schema->properties ?? null) ? array_keys(get_object_vars($schema->properties)) : [];
            $extra = array_values(array_diff($error->args()['properties'], $declared));
            if ($extra === []) {
                return null;
            }

            return ($schema->additionalProperties ?? null) === false
                ? 'Additional object properties are not allowed: '.implode(', ', $extra)
                : $formatter->formatErrorMessage($error, $message);
        });
        $errors = [];
        foreach ($formatted as $pointer => $messages) {
            $message = current(array_filter($messages, 'is_string'));
            if ($message === false) {
                continue;
            }
            $segments = array_map(static fn (string $part): string => str_replace(['~1', '~0'], ['/', '~'], $part), explode('/', ltrim($pointer, '/')));
            $key = implode('.', $segments);
            $errors[$key === '' ? 'inputs' : $key] = $message;
        }

        // Never report an invalid document as valid because every message was a filtered duplicate.
        return $errors !== [] ? $errors : ['inputs' => 'The input does not match the model contract.'];
    }

    /** Preserve JSON object/array distinctions at known schema boundaries, including empty objects. */
    public static function dataObject(array|bool $schema, mixed $value): mixed
    {
        if (! is_array($value) && ! $value instanceof stdClass) {
            return $value;
        }
        $schema = is_array($schema) ? $schema : [];
        foreach (['allOf', 'oneOf', 'anyOf'] as $keyword) {
            foreach ($schema[$keyword] ?? [] as $branch) {
                if (! is_array($branch)) {
                    continue;
                }
                $type = $branch['type'] ?? null;
                if (($type === 'object' && ($value instanceof stdClass || ! array_is_list((array) $value) || $value === []))
                    || ($type === 'array' && is_array($value) && array_is_list($value))) {
                    return self::dataObject($branch, $value);
                }
            }
        }
        $isObject = $value instanceof stdClass || ($schema['type'] ?? null) === 'object' || isset($schema['properties']) || ! array_is_list((array) $value);
        $result = [];
        foreach ((array) $value as $name => $item) {
            $child = $isObject
                ? ($schema['properties'][$name] ?? $schema['additionalProperties'] ?? true)
                : ($schema['prefixItems'][$name] ?? $schema['items'] ?? true);
            $result[$name] = self::dataObject($child, $item);
        }

        return $isObject ? (object) $result : $result;
    }

    private static function schemaObject(array|bool $schema): object|bool
    {
        if (is_bool($schema)) {
            return $schema;
        }
        $result = (object) $schema;
        foreach (self::MAPS as $keyword) {
            if (isset($schema[$keyword])) {
                $result->{$keyword} = (object) array_map(self::schemaObject(...), $schema[$keyword]);
            }
        }
        foreach (self::LISTS as $keyword) {
            if (isset($schema[$keyword])) {
                $result->{$keyword} = array_map(self::schemaObject(...), $schema[$keyword]);
            }
        }
        foreach (self::CHILDREN as $keyword) {
            if (isset($schema[$keyword]) && (is_array($schema[$keyword]) || is_bool($schema[$keyword]))) {
                $result->{$keyword} = self::schemaObject($schema[$keyword]);
            }
        }
        foreach (['default', 'const'] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                $result->{$keyword} = self::dataObject($schema, $schema[$keyword]);
            }
        }

        return $result;
    }

    /** @return array<int, array{path: array, asset_id: string, kind: string, role: string}> */
    public static function assetReferences(array $schema, mixed $values): array
    {
        $found = [];
        self::visit($schema, $values, [], static function (array $node, mixed $value, array $path) use (&$found): void {
            $asset = $node['x-workspace-asset'] ?? null;
            // A public link in a file field is passed to the provider unchanged, never staged as an owned file.
            if (! is_array($asset) || ! self::isAssetId($value)) {
                return;
            }
            $key = json_encode($path, JSON_THROW_ON_ERROR);
            $entry = [...$asset, 'path' => $path, 'asset_id' => $value];
            if (isset($found[$key]) && $found[$key]['kind'] !== $entry['kind']) {
                if ($entry['kind'] === 'file') {
                    return;
                }
                if ($found[$key]['kind'] !== 'file') {
                    throw new CapabilityValidationException([implode('.', $path) => 'The selected file has ambiguous media requirements.']);
                }
            }
            $found[$key] = $entry;
        });

        return array_values($found);
    }

    /** Map only validated file positions; identical UUIDs in separate roles stay separate. */
    public static function replaceAssets(array $schema, mixed $values, callable $replace): mixed
    {
        foreach (self::assetReferences($schema, $values) as $asset) {
            $target = &$values;
            foreach ($asset['path'] as $segment) {
                if ($target instanceof stdClass) {
                    $target = &$target->{$segment};
                } else {
                    $target = &$target[$segment];
                }
            }
            $target = $replace($asset);
            unset($target);
        }

        return $values;
    }

    private static function visit(array|bool $schema, mixed $value, array $path, callable $visitor): void
    {
        if (is_bool($schema)) {
            return;
        }
        $visitor($schema, $value, $path);
        foreach ($schema['allOf'] ?? [] as $branch) {
            self::visit($branch, $value, $path, $visitor);
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach ($schema[$keyword] ?? [] as $branch) {
                if (self::errors($branch, $value) === []) {
                    self::visit($branch, $value, $path, $visitor);
                }
            }
        }
        if (isset($schema['if'])) {
            $branch = self::errors($schema['if'], $value) === [] ? ($schema['then'] ?? true) : ($schema['else'] ?? true);
            self::visit($branch, $value, $path, $visitor);
        }
        if (! is_array($value) && ! $value instanceof stdClass) {
            return;
        }
        $object = $value instanceof stdClass || ($schema['type'] ?? null) === 'object' || isset($schema['properties']) || ! array_is_list((array) $value);
        foreach ((array) $value as $name => $item) {
            $child = $object
                ? ($schema['properties'][$name] ?? $schema['additionalProperties'] ?? true)
                : ($schema['prefixItems'][$name] ?? $schema['items'] ?? true);
            self::visit($child, $item, [...$path, $name], $visitor);
        }
    }

    private static function assertBounded(mixed $value, int $depth = 0, int &$nodes = 0): void
    {
        if (++$nodes > 25000 || $depth > 64 || (is_string($value) && strlen($value) > 2_097_152)) {
            throw new CapabilityValidationException(['inputs' => 'The request exceeds the structured input safety limit.']);
        }
        if (is_array($value) || $value instanceof stdClass) {
            foreach ((array) $value as $item) {
                self::assertBounded($item, $depth + 1, $nodes);
            }
        }
    }
}
