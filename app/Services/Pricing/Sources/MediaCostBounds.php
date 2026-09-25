<?php

namespace App\Services\Pricing\Sources;

use App\Models\AiModelProfile;
use App\Services\FalProtocol;
use App\Services\KinoviProtocol;

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

    public static function properties(AiModelProfile $model): array
    {
        return $model->capabilityRevisions->sortByDesc('id')->first()?->definition['input_schema']['properties'] ?? [];
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
        $native = self::native($model);
        if (($native['durations'] ?? []) !== []) {
            return $minimum ? min($native['durations']) : max($native['durations']);
        }
        $schema = self::properties($model)['duration'] ?? [];
        if (! is_array($schema)) {
            return null;
        }
        if (! $minimum) {
            return self::maximum($schema) ?? ($native['duration_max'] ?? null);
        }
        $values = $schema['enum'] ?? [];
        return $values !== [] && count(array_filter($values, 'is_numeric')) === count($values)
            ? (float) min($values) : ($schema['minimum'] ?? $native['duration_min'] ?? null);
    }

    public static function megapixels(AiModelProfile $model): ?float
    {
        $properties = self::properties($model);
        $size = $properties['image_size'] ?? [];
        $sizes = $size['enum'] ?? (self::native($model)['sizes'] ?? []);
        $pixels = [];
        $named = ['square' => [512, 512], 'square_hd' => [1024, 1024], 'portrait_4_3' => [768, 1024], 'portrait_16_9' => [576, 1024], 'landscape_4_3' => [1024, 768], 'landscape_16_9' => [1024, 576]];
        foreach ($sizes as $value) {
            if (is_string($value) && preg_match('/^(\d+)x(\d+)$/', $value, $match)) {
                $pixels[] = (int) $match[1] * (int) $match[2];
            } elseif (isset($named[$value])) {
                $pixels[] = array_product($named[$value]);
            } else {
                return null;
            }
        }
        $width = self::maximum($properties['width'] ?? $size['properties']['width'] ?? []);
        $height = self::maximum($properties['height'] ?? $size['properties']['height'] ?? []);
        if ($width !== null && $height !== null) {
            $pixels[] = $width * $height;
        }
        return $pixels === [] ? null : max($pixels) / 1_000_000;
    }
}
