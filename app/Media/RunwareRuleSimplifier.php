<?php

namespace App\Media;

/**
 * Partially evaluates provider JSON-Schema rules against what one operation guarantees about
 * property presence. Only presence is decided: any value test stays unknown, so a rule is
 * dropped or rewritten only when its outcome is certain for every request the operation admits.
 * Presence comes from the (already restricted) root object schema: a declared member is
 * required or optional, an undeclared member of a closed object is absent. Members the
 * transport always sends are injected as present.
 */
final class RunwareRuleSimplifier
{
    public const ABSENT = 0;

    public const OPTIONAL = 1;

    public const REQUIRED = 2;

    private const NO = 0;

    private const YES = 1;

    private const MAYBE = 2;

    private const ANNOTATIONS = ['title', 'description', 'default', 'examples', '$comment', 'deprecated', 'readOnly', 'writeOnly', '$id', '$schema'];

    /**
     * @param  array<string, mixed>  $schema  root object schema
     * @param  list<string>  $injected  root members the transport always sends
     */
    public function __construct(private readonly array $schema, private readonly array $injected = []) {}

    /** Presence of a member (relative to its parent being present), honoring in-rule assumptions. */
    public function presence(array $path, array $assumed = []): int
    {
        if ($path === []) {
            return self::REQUIRED;
        }
        if (count($path) === 1 && in_array($path[0], $this->injected, true)) {
            return self::REQUIRED;
        }
        $parent = $this->node(array_slice($path, 0, -1));
        $name = (string) end($path);
        if ($parent === null) {
            return isset($assumed[self::key($path)]) ? self::REQUIRED : self::OPTIONAL;
        }
        $properties = is_array($parent['properties'] ?? null) ? $parent['properties'] : [];
        if (array_key_exists($name, $properties)) {
            if ($properties[$name] === false) {
                return self::ABSENT;
            }

            return in_array($name, (array) ($parent['required'] ?? []), true) || isset($assumed[self::key($path)]) ? self::REQUIRED : self::OPTIONAL;
        }
        $closed = ($parent['additionalProperties'] ?? true) === false && ($parent['patternProperties'] ?? []) === [];
        if ($closed) {
            return self::ABSENT;
        }

        return isset($assumed[self::key($path)]) ? self::REQUIRED : self::OPTIONAL;
    }

    /** YES (1), NO (0) or MAYBE (2) for every request the operation admits. */
    public function truth(array|bool $node, array $path = [], array $assumed = []): int
    {
        if (is_bool($node)) {
            return $node ? self::YES : self::NO;
        }
        $local = $this->assume($node, $path, $assumed);
        $results = [];
        foreach ($node as $keyword => $value) {
            $results[] = match ($keyword) {
                'required' => $this->requiredTruth((array) $value, $path, $assumed),
                'properties' => is_array($value) ? $this->propertiesTruth($value, $path, $local) : self::MAYBE,
                'allOf' => self::all(array_map(fn ($child) => $this->truthOf($child, $path, $local), (array) $value)),
                'anyOf' => self::any(array_map(fn ($child) => $this->truthOf($child, $path, $local), (array) $value)),
                'oneOf' => self::one(array_map(fn ($child) => $this->truthOf($child, $path, $local), (array) $value)),
                'not' => self::negate($this->truthOf($value, $path, $local)),
                'if' => $this->conditionalTruth($node, $path, $local),
                'then', 'else' => self::YES,
                'dependentRequired' => is_array($value) ? $this->dependentTruth($value, $path, $local) : self::MAYBE,
                'dependentSchemas' => is_array($value) ? $this->dependentSchemaTruth($value, $path, $local) : self::MAYBE,
                'type' => in_array('object', (array) $value, true) && $this->isObject($path) ? self::YES : self::MAYBE,
                'additionalProperties' => $value === true ? self::YES : self::MAYBE,
                default => in_array($keyword, self::ANNOTATIONS, true) || str_starts_with((string) $keyword, 'x-') ? self::YES : self::MAYBE,
            };
        }

        return self::all($results);
    }

    /** An equivalent rule for every admitted request: true (always satisfied), false (never), or a smaller schema. */
    public function simplify(array|bool $node, array $path = [], array $assumed = []): array|bool
    {
        if (is_bool($node)) {
            return $node;
        }
        $truth = $this->truth($node, $path, $assumed);
        if ($truth !== self::MAYBE) {
            return $truth === self::YES;
        }
        $local = $this->assume($node, $path, $assumed);
        $out = [];
        $extra = [];
        foreach ($node as $keyword => $value) {
            switch ($keyword) {
                case 'required':
                    $names = array_values(array_filter((array) $value, fn ($name) => ! is_string($name) || $this->presence([...$path, $name], $assumed) !== self::REQUIRED));
                    if ($names !== []) {
                        $out['required'] = $names;
                    }
                    break;
                case 'properties':
                    foreach (is_array($value) ? $value : [] as $name => $child) {
                        if ($this->presence([...$path, (string) $name], $local) === self::ABSENT) {
                            continue;
                        }
                        $simplified = is_array($child) || is_bool($child) ? $this->simplify($child, [...$path, (string) $name], $local) : $child;
                        if ($simplified !== true) {
                            $out['properties'][$name] = $simplified;
                        }
                    }
                    break;
                case 'allOf':
                    foreach ((array) $value as $child) {
                        $simplified = $this->simplifyOf($child, $path, $local);
                        if ($simplified !== true) {
                            $extra[] = $simplified;
                        }
                    }
                    break;
                case 'anyOf':
                    $branches = [];
                    foreach ((array) $value as $child) {
                        $simplified = $this->simplifyOf($child, $path, $local);
                        if ($simplified === true) {
                            $branches = null;
                            break;
                        }
                        if ($simplified !== false) {
                            $branches[] = $simplified;
                        }
                    }
                    if ($branches !== null) {
                        count($branches) === 1 ? $extra[] = $branches[0] : $out['anyOf'] = $branches;
                    }
                    break;
                case 'oneOf':
                    $branches = [];
                    foreach ((array) $value as $child) {
                        $simplified = $this->simplifyOf($child, $path, $local);
                        if ($simplified !== false) {
                            $branches[] = $simplified;
                        }
                    }
                    count($branches) === 1 ? $extra[] = $branches[0] : $out['oneOf'] = $branches;
                    break;
                case 'not':
                    $simplified = $this->simplifyOf($value, $path, $local);
                    if ($simplified === true) {
                        return false;
                    }
                    if ($simplified !== false) {
                        $out['not'] = $simplified;
                    }
                    break;
                case 'if':
                case 'then':
                case 'else':
                    break;
                case 'dependentRequired':
                    foreach (is_array($value) ? $value : [] as $key => $dependencies) {
                        if ($this->presence([...$path, (string) $key], $local) === self::ABSENT) {
                            continue;
                        }
                        $missing = array_filter((array) $dependencies, fn ($name) => is_string($name) && $this->presence([...$path, $name], $local) === self::ABSENT);
                        if ($missing !== []) {
                            // A member whose dependency can never be sent must itself be absent.
                            $extra[] = ['not' => ['required' => [(string) $key]]];

                            continue;
                        }
                        $open = array_values(array_filter((array) $dependencies, fn ($name) => ! is_string($name) || $this->presence([...$path, $name], $local) !== self::REQUIRED));
                        if ($open !== []) {
                            $out['dependentRequired'][$key] = $open;
                        }
                    }
                    break;
                case 'dependentSchemas':
                    foreach (is_array($value) ? $value : [] as $key => $child) {
                        if ($this->presence([...$path, (string) $key], $local) === self::ABSENT) {
                            continue;
                        }
                        $simplified = $this->simplifyOf($child, $path, $local);
                        if ($simplified !== true) {
                            $out['dependentSchemas'][$key] = $simplified;
                        }
                    }
                    break;
                default:
                    $out[$keyword] = $value;
            }
        }
        if (array_key_exists('if', $node)) {
            $condition = $this->truthOf($node['if'], $path, $local);
            $then = $node['then'] ?? true;
            $else = $node['else'] ?? true;
            if ($condition === self::YES) {
                $extra[] = $this->simplifyOf($then, $path, $local);
            } elseif ($condition === self::NO) {
                $extra[] = $this->simplifyOf($else, $path, $local);
            } else {
                $thenRule = $this->simplifyOf($then, $path, $local);
                $elseRule = $this->simplifyOf($else, $path, $local);
                $conditionRule = $this->simplifyOf($node['if'], $path, $local);
                if ($thenRule === false && $elseRule === false) {
                    return false;
                }
                if ($thenRule === false && $elseRule === true) {
                    $extra[] = ['not' => $conditionRule];
                } elseif ($thenRule === true && $elseRule === false) {
                    $extra[] = $conditionRule;
                } elseif ($thenRule !== true || $elseRule !== true) {
                    $out['if'] = $conditionRule;
                    if ($thenRule !== true) {
                        $out['then'] = $thenRule;
                    }
                    if ($elseRule !== true) {
                        $out['else'] = $elseRule;
                    }
                }
            }
        }
        foreach ($extra as $rule) {
            if ($rule === false) {
                return false;
            }
        }
        $extra = array_values(array_filter($extra, static fn ($rule) => $rule !== true));
        $constraints = array_diff_key($out, array_flip(self::ANNOTATIONS));
        if ($constraints === [] && count($extra) === 1) {
            return $extra[0];
        }
        if ($extra !== []) {
            $out['allOf'] = $extra;
        }

        return array_diff_key($out, array_flip(self::ANNOTATIONS)) === [] ? true : $out;
    }

    /**
     * Member paths a simplified rule makes mandatory, or null when it is not a plain presence requirement.
     *
     * @return list<list<string>>|null
     */
    public static function requiredPaths(array|bool $rule, array $path = []): ?array
    {
        if (! is_array($rule) || $rule === []) {
            return null;
        }
        $keys = array_keys($rule);
        if ($keys === ['allOf']) {
            $paths = [];
            foreach ((array) $rule['allOf'] as $child) {
                $found = self::requiredPaths($child, $path);
                if ($found === null) {
                    return null;
                }
                array_push($paths, ...$found);
            }

            return $paths;
        }
        if (array_diff($keys, ['required', 'properties']) !== [] || ! is_array($rule['required'] ?? null)) {
            return null;
        }
        $required = array_values(array_filter($rule['required'], 'is_string'));
        $paths = array_map(static fn (string $name): array => [...$path, $name], $required);
        foreach ((array) ($rule['properties'] ?? []) as $name => $child) {
            if (! in_array($name, $required, true)) {
                return null;
            }
            $found = self::requiredPaths($child, [...$path, (string) $name]);
            if ($found === null) {
                return null;
            }
            array_push($paths, ...$found);
        }

        return $paths;
    }

    /**
     * Member paths a simplified rule forbids outright (e.g. not: {required: [strength]}), or null.
     *
     * @return list<list<string>>|null
     */
    public static function forbiddenPaths(array|bool $rule, array $path = []): ?array
    {
        if (! is_array($rule) || $rule === []) {
            return null;
        }
        $keys = array_keys($rule);
        if ($keys === ['not']) {
            return self::anyPresent($rule['not'], $path);
        }
        if ($keys === ['allOf']) {
            $paths = [];
            foreach ((array) $rule['allOf'] as $child) {
                $found = self::forbiddenPaths($child, $path);
                if ($found === null) {
                    return null;
                }
                array_push($paths, ...$found);
            }

            return $paths;
        }
        if ($keys === ['properties'] && is_array($rule['properties'])) {
            $paths = [];
            foreach ($rule['properties'] as $name => $child) {
                if ($child !== false) {
                    return null;
                }
                $paths[] = [...$path, (string) $name];
            }

            return $paths === [] ? null : $paths;
        }

        return null;
    }

    /**
     * Paths whose presence alone decides a rule node (defaults there would change its outcome).
     *
     * @return list<list<string>>
     */
    public static function presenceReferences(array|bool $node, array $path = []): array
    {
        if (! is_array($node)) {
            return [];
        }
        $paths = [];
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        foreach ((array) ($node['required'] ?? []) as $name) {
            if (is_string($name) && ! self::valueGuarded($properties[$name] ?? null)) {
                $paths[] = [...$path, $name];
            }
        }
        foreach (is_array($node['dependentRequired'] ?? null) ? $node['dependentRequired'] : [] as $key => $dependencies) {
            $paths[] = [...$path, (string) $key];
            foreach ((array) $dependencies as $name) {
                if (is_string($name)) {
                    $paths[] = [...$path, $name];
                }
            }
        }
        foreach ($properties as $name => $child) {
            array_push($paths, ...self::presenceReferences($child, [...$path, (string) $name]));
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            foreach ((array) ($node[$keyword] ?? []) as $child) {
                array_push($paths, ...self::presenceReferences($child, $path));
            }
        }
        foreach (['not', 'if', 'then', 'else'] as $keyword) {
            if (isset($node[$keyword])) {
                array_push($paths, ...self::presenceReferences($node[$keyword], $path));
            }
        }
        foreach (is_array($node['dependentSchemas'] ?? null) ? $node['dependentSchemas'] : [] as $key => $child) {
            $paths[] = [...$path, (string) $key];
            array_push($paths, ...self::presenceReferences($child, $path));
        }

        return $paths;
    }

    /** Root member names a rule mentions anywhere (for transport-field safety checks). */
    public static function mentionedRootMembers(array|bool $node): array
    {
        if (! is_array($node)) {
            return [];
        }
        $names = array_values(array_filter((array) ($node['required'] ?? []), 'is_string'));
        array_push($names, ...array_map('strval', array_keys(is_array($node['properties'] ?? null) ? $node['properties'] : [])));
        foreach (is_array($node['dependentRequired'] ?? null) ? $node['dependentRequired'] : [] as $key => $dependencies) {
            $names[] = (string) $key;
            array_push($names, ...array_filter((array) $dependencies, 'is_string'));
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            foreach ((array) ($node[$keyword] ?? []) as $child) {
                array_push($names, ...self::mentionedRootMembers($child));
            }
        }
        foreach (['not', 'if', 'then', 'else'] as $keyword) {
            if (isset($node[$keyword])) {
                array_push($names, ...self::mentionedRootMembers($node[$keyword]));
            }
        }

        return array_values(array_unique($names));
    }

    /** A node is satisfied iff any listed path is present; null when it is not such a disjunction. */
    private static function anyPresent(mixed $node, array $path): ?array
    {
        if (! is_array($node) || $node === []) {
            return null;
        }
        $keys = array_keys($node);
        if ($keys === ['required'] && is_array($node['required']) && count($node['required']) === 1 && is_string($node['required'][0] ?? null)) {
            return [[...$path, $node['required'][0]]];
        }
        if ($keys === ['anyOf'] || ($keys === ['allOf'] && count((array) $node['allOf']) === 1)) {
            $paths = [];
            foreach ((array) $node[$keys[0]] as $child) {
                $found = self::anyPresent($child, $path);
                if ($found === null) {
                    return null;
                }
                array_push($paths, ...$found);
            }

            return $paths === [] ? null : $paths;
        }
        sort($keys);
        if ($keys === ['properties', 'required'] && is_array($node['properties']) && count($node['properties']) === 1
            && is_array($node['required']) && array_keys($node['properties']) === $node['required']) {
            $name = (string) array_key_first($node['properties']);

            return self::anyPresent($node['properties'][$name], [...$path, $name]);
        }

        return null;
    }

    private static function valueGuarded(mixed $schema): bool
    {
        if (! is_array($schema)) {
            return false;
        }

        return array_diff(array_keys($schema), self::ANNOTATIONS) !== [];
    }

    private function truthOf(mixed $node, array $path, array $assumed): int
    {
        return is_array($node) || is_bool($node) ? $this->truth($node, $path, $assumed) : self::MAYBE;
    }

    private function simplifyOf(mixed $node, array $path, array $assumed): array|bool
    {
        // A non-schema member is rejected when the finished contract is parsed; it constrains nothing here.
        return is_array($node) || is_bool($node) ? $this->simplify($node, $path, $assumed) : true;
    }

    private function assume(array $node, array $path, array $assumed): array
    {
        foreach ((array) ($node['required'] ?? []) as $name) {
            if (is_string($name)) {
                $assumed[self::key([...$path, $name])] = true;
            }
        }

        return $assumed;
    }

    private function requiredTruth(array $names, array $path, array $assumed): int
    {
        $results = [];
        foreach ($names as $name) {
            if (! is_string($name)) {
                $results[] = self::MAYBE;

                continue;
            }
            $results[] = match ($this->presence([...$path, $name], $assumed)) {
                self::REQUIRED => self::YES,
                self::ABSENT => self::NO,
                default => self::MAYBE,
            };
        }

        return self::all($results);
    }

    private function propertiesTruth(array $properties, array $path, array $local): int
    {
        $results = [];
        foreach ($properties as $name => $child) {
            $presence = $this->presence([...$path, (string) $name], $local);
            if ($presence === self::ABSENT) {
                $results[] = self::YES;

                continue;
            }
            $truth = $this->truthOf($child, [...$path, (string) $name], $local);
            $results[] = $truth === self::YES ? self::YES : ($presence === self::REQUIRED ? $truth : self::MAYBE);
        }

        return self::all($results);
    }

    private function conditionalTruth(array $node, array $path, array $local): int
    {
        $condition = $this->truthOf($node['if'], $path, $local);
        $then = $this->truthOf($node['then'] ?? true, $path, $local);
        $else = $this->truthOf($node['else'] ?? true, $path, $local);

        return match ($condition) {
            self::YES => $then,
            self::NO => $else,
            default => $then === $else && $then !== self::MAYBE ? $then : self::MAYBE,
        };
    }

    private function dependentTruth(array $dependencies, array $path, array $local): int
    {
        $results = [];
        foreach ($dependencies as $key => $names) {
            $presence = $this->presence([...$path, (string) $key], $local);
            if ($presence === self::ABSENT) {
                $results[] = self::YES;

                continue;
            }
            $states = array_map(fn ($name) => is_string($name) ? $this->presence([...$path, $name], $local) : self::OPTIONAL, (array) $names);
            $results[] = ! in_array(self::ABSENT, $states, true) && ! in_array(self::OPTIONAL, $states, true) ? self::YES
                : ($presence === self::REQUIRED && in_array(self::ABSENT, $states, true) ? self::NO : self::MAYBE);
        }

        return self::all($results);
    }

    private function dependentSchemaTruth(array $schemas, array $path, array $local): int
    {
        $results = [];
        foreach ($schemas as $key => $child) {
            $presence = $this->presence([...$path, (string) $key], $local);
            if ($presence === self::ABSENT) {
                $results[] = self::YES;

                continue;
            }
            $truth = $this->truthOf($child, $path, $local);
            $results[] = $truth === self::YES ? self::YES : ($presence === self::REQUIRED ? $truth : self::MAYBE);
        }

        return self::all($results);
    }

    private function isObject(array $path): bool
    {
        if ($path === []) {
            return true;
        }
        $node = $this->node($path);

        return $node !== null && in_array('object', (array) ($node['type'] ?? []), true);
    }

    /** The declared object schema at a member path, following properties only. */
    private function node(array $path): ?array
    {
        $node = $this->schema;
        foreach ($path as $segment) {
            $next = is_array($node['properties'] ?? null) ? ($node['properties'][$segment] ?? null) : null;
            if (! is_array($next)) {
                return null;
            }
            $node = $next;
        }

        return $node;
    }

    private static function key(array $path): string
    {
        return implode("\0", $path);
    }

    private static function all(array $results): int
    {
        if (in_array(self::NO, $results, true)) {
            return self::NO;
        }

        return in_array(self::MAYBE, $results, true) ? self::MAYBE : self::YES;
    }

    private static function any(array $results): int
    {
        if ($results === [] || ! array_diff($results, [self::NO])) {
            return self::NO;
        }

        return in_array(self::YES, $results, true) ? self::YES : self::MAYBE;
    }

    private static function one(array $results): int
    {
        $yes = count(array_filter($results, static fn ($result) => $result === self::YES));
        $maybe = count(array_filter($results, static fn ($result) => $result === self::MAYBE));
        if ($yes >= 2 || ($yes === 0 && $maybe === 0)) {
            return self::NO;
        }

        return $yes === 1 && $maybe === 0 ? self::YES : self::MAYBE;
    }

    private static function negate(int $result): int
    {
        return match ($result) {
            self::YES => self::NO,
            self::NO => self::YES,
            default => self::MAYBE,
        };
    }
}
