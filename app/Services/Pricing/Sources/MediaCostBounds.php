<?php

namespace App\Services\Pricing\Sources;

use App\Models\AiModelProfile;
use App\Services\FalProtocol;
use App\Services\KinoviProtocol;
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

    public static function properties(AiModelProfile $model): array
    {
        return self::revisions($model)->first()?->definition['input_schema']['properties'] ?? [];
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
        $schemas = self::revisions($model)->map(fn ($revision) => $revision->definition['input_schema']['properties'] ?? [])->filter()->all();
        if ($schemas === []) {
            $native = self::native($model);
            if (($native['durations'] ?? []) !== []) {
                return $minimum ? min($native['durations']) : max($native['durations']);
            }
            return $native[$minimum ? 'duration_min' : 'duration_max'] ?? null;
        }
        $bounds = [];
        foreach ($schemas as $properties) {
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
        return $minimum ? min($bounds) : max($bounds);
    }

    public static function megapixels(AiModelProfile $model): ?float
    {
        $schemas = self::revisions($model)->map(fn ($revision) => $revision->definition['input_schema']['properties'] ?? [])->filter()->all();
        if ($schemas === []) {
            $schemas = [['image_size' => ['enum' => self::native($model)['sizes'] ?? []]]];
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
