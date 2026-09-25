<?php

namespace App\Services\Pricing\Sources;

use App\Models\AiModelProfile;
use App\Services\FalProtocol;
use App\Services\KinoviProtocol;
use App\Services\MediaModelConfig;
use App\Services\ThreeDProtocol;
use Illuminate\Support\Collection;

/** Bounds come from selectable inputs, never sample/default configurations. */
final class MediaCostBounds
{
    public static function native(AiModelProfile $model): array
    {
        $id = $model->upstream_model_id ?: $model->model_id;
        return match ($model->provider?->protocol) {
            'fal' => FalProtocol::mediaConfig($id) ?? [],
            'kinovi' => KinoviProtocol::mediaConfig($id) ?? [],
            default => [],
        };
    }

    public static function revisions(AiModelProfile $model): Collection
    {
        $all = $model->capabilityRevisions->sortByDesc('id');
        $reviewed = $all->filter(fn ($revision) => $revision->status === 'published' || $revision->reviewed_at !== null);
        return $reviewed->isEmpty() ? $all->take(1) : $reviewed;
    }

    /**
     * Revisions a member can execute. While a model still runs natively, only a published v2 contract (or a
     * published v1 catalog image) replaces that path; imported or merely reviewed candidates never bound its prices.
     */
    public static function contractRevisions(AiModelProfile $model): Collection
    {
        $revisions = self::revisions($model);

        return self::executesNatively($model) ? $revisions->where('status', 'published')->values() : $revisions;
    }

    public static function properties(AiModelProfile $model): array
    {
        return self::contractRevisions($model)->first()?->definition['input_schema']['properties'] ?? [];
    }

    public static function maximum(array $schema): ?float
    {
        if (is_array($schema['enum'] ?? null) && $schema['enum'] !== []) {
            $numbers = array_map(static fn ($v) => is_numeric($v) ? (float) $v : null, $schema['enum']);
            return in_array(null, $numbers, true) ? null : max($numbers);
        }
        return is_numeric($schema['maximum'] ?? null) ? (float) $schema['maximum'] : null;
    }

    public static function duration(AiModelProfile $model, bool $minimum = false): ?float
    {
        $bounds = [];
        $native = self::native($model);
        if (($native['durations'] ?? []) !== []) {
            $bounds[] = (float) ($minimum ? min($native['durations']) : max($native['durations']));
        } elseif (is_numeric($native[$minimum ? 'duration_min' : 'duration_max'] ?? null)) {
            $bounds[] = (float) $native[$minimum ? 'duration_min' : 'duration_max'];
        }
        foreach (self::contractRevisions($model) as $revision) {
            $properties = $revision->definition['input_schema']['properties'] ?? [];
            if ($properties === []) {
                continue;
            }
            $schema = $properties['duration'] ?? [];
            $values = $schema['enum'] ?? [];
            $bound = $minimum
                ? ($values !== [] && count(array_filter($values, 'is_numeric')) === count($values) ? (float) min($values) : ($schema['minimum'] ?? null))
                : self::maximum($schema);
            if (! is_numeric($bound) || $bound <= 0) {
                return null;
            }
            $bounds[] = (float) $bound;
        }
        return $bounds === [] ? null : ($minimum ? min($bounds) : max($bounds));
    }

    private static function executesNatively(AiModelProfile $model): bool
    {
        return self::native($model) !== [] || ThreeDProtocol::supports($model) || MediaModelConfig::hasCatalogImage($model);
    }

    /**
     * The most outputs one billed invocation can return. Native v1 execution bills each output (count) and a
     * v2 contract whose reviewed quantity input multiplies a per-generation or per-second tariff bills per
     * output too; any other executable v2 contract charges its tariff once per request, however many outputs
     * its schema lets a member ask for. Null when such a count has no numeric maximum.
     */
    public static function outputsPerInvocation(AiModelProfile $model, string $unit): ?int
    {
        $outputs = 1;
        foreach (self::contractRevisions($model)->where('contract_version', 2) as $revision) {
            $schema = $revision->definition['input_schema'] ?? null;
            $properties = is_array($schema) ? self::inputProperties($schema) : [];
            $bound = $revision->provider_bindings['quantity_input'] ?? null;
            if (is_string($bound) && isset($properties[$bound]) && in_array($unit, ['generation', 'second'], true)) {
                continue;
            }
            // The same result-count fields the OpenAI image mapping fills for a member's `n`.
            foreach (array_unique(array_filter([$bound, 'numberResults', 'num_images', 'num_outputs', 'n'])) as $field) {
                if (! is_array($properties[$field] ?? null)) {
                    continue;
                }
                $maximum = self::maximum($properties[$field]);
                if ($maximum === null) {
                    return null;
                }
                $outputs = max($outputs, (int) ceil($maximum));
            }
        }
        return $outputs;
    }

    /** Top-level input properties, including members declared through allOf branches. */
    private static function inputProperties(array $schema): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach (is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [] as $branch) {
            if (is_array($branch)) {
                $properties = array_replace_recursive($properties, self::inputProperties($branch));
            }
        }
        return $properties;
    }

    public static function megapixels(AiModelProfile $model): ?float
    {
        $schemas = self::contractRevisions($model)->map(fn ($revision) => $revision->definition['input_schema']['properties'] ?? [])->filter()->values()->all();
        if (($sizes = self::native($model)['sizes'] ?? []) !== []) {
            // Native execution offers exactly these sizes alongside any published contract.
            $schemas[] = ['image_size' => ['enum' => $sizes]];
        }
        if ($schemas === []) {
            return null;
        }
        $pixels = [];
        foreach ($schemas as $properties) {
            if (isset($properties['width'], $properties['height'])) {
                $width = self::maximum($properties['width']);
                $height = self::maximum($properties['height']);
                $bound = $width !== null && $height !== null ? $width * $height : null;
            } else {
                $bound = self::sizePixels($properties['image_size'] ?? []);
            }
            if ($bound === null) {
                return null;
            }
            $pixels[] = $bound;
        }
        return max($pixels) / 1_000_000;
    }

    private static function sizePixels(array $schema): ?float
    {
        $branches = $schema['anyOf'] ?? $schema['oneOf'] ?? null;
        if (is_array($branches)) {
            $bounds = array_map(self::sizePixels(...), $branches);
            return $bounds === [] || in_array(null, $bounds, true) ? null : max($bounds);
        }
        if (isset($schema['properties']['width'], $schema['properties']['height'])) {
            $width = self::maximum($schema['properties']['width']);
            $height = self::maximum($schema['properties']['height']);
            return $width !== null && $height !== null ? $width * $height : null;
        }
        $pixels = [];
        $named = ['square' => [512, 512], 'square_hd' => [1024, 1024], 'portrait_4_3' => [768, 1024], 'portrait_16_9' => [576, 1024], 'landscape_4_3' => [1024, 768], 'landscape_16_9' => [1024, 576]];
        foreach ($schema['enum'] ?? [] as $value) {
            if (is_string($value) && preg_match('/^(\d+)x(\d+)$/', $value, $match)) {
                $pixels[] = (int) $match[1] * (int) $match[2];
            } elseif (is_string($value) && isset($named[$value])) {
                $pixels[] = array_product($named[$value]);
            } else {
                return null;
            }
        }
        return $pixels === [] ? null : max($pixels);
    }
}
