<?php

namespace App\Services;

use App\Exceptions\AiProxyException;

/**
 * Kinovi (kinovi.ai) media-generation adapter. Kinovi is a task-based API:
 * POST /api/v1/jobs/createTask returns {taskId}; GET /api/v1/jobs/recordInfo?taskId=
 * returns {status: waiting|generating|success|fail, output:[{url,...}]}. It is NOT
 * OpenAI-compatible, so it gets its own protocol like fal. This first cut supports
 * image models; the transport polls the task synchronously and returns image URLs.
 */
final class KinoviProtocol
{
    /** Curated public model id => category. Image only for now. */
    public const MODELS = [
        'gpt-image-2' => 'image',
        'seedream-5.0-pro' => 'image',
        'nanobanana-pro' => 'image',
        'nanobanana2' => 'image',
    ];

    public const NAMES = [
        'gpt-image-2' => 'GPT Image 2',
        'seedream-5.0-pro' => 'Seedream 5.0 Pro',
        'nanobanana-pro' => 'Nano Banana Pro',
        'nanobanana2' => 'NanoBanana 2',
    ];

    // App image sizes map to Kinovi's aspectRatio + resolution tier (1k = cheapest).
    private const SIZE_MAP = [
        '1024x1024' => ['1:1', '1k'],
        '1024x1792' => ['9:16', '1k'],
        '1792x1024' => ['16:9', '1k'],
    ];

    public static function mediaConfig(string $model): ?array
    {
        if ((self::MODELS[$model] ?? null) !== 'image') {
            return null;
        }

        return [
            'image_path' => $model,
            'video_path' => null,
            'video_status_path' => null,
            'sizes' => array_keys(self::SIZE_MAP),
            'durations' => [],
            'aspect_ratios' => [],
            'max_quantity' => 4,
            'supports_size' => true,
            // Kinovi createTask produces one asset per task; the proxy loops for quantity.
            'supports_n' => false,
            'supports_duration' => false,
            'supports_aspect_ratio' => false,
            'supports_pro' => false,
            'supports_reference_image' => false,
            'reference_required' => false,
            'reference_model' => null,
            'audio_path' => null,
            'audio_status_path' => null,
            'audio_kind' => null,
            'voices' => [],
            'speed_min' => null, 'speed_max' => null, 'speed_default' => null,
            'duration_min' => null, 'duration_max' => null, 'duration_default' => null,
            'max_characters' => null,
        ];
    }

    /** Build the createTask body for an image request. */
    public static function imageTask(array $payload): array
    {
        $model = $payload['model'] ?? null;
        if (! is_string($model) || (self::MODELS[$model] ?? null) !== 'image') {
            throw new AiProxyException('This Kinovi image model is not supported.', 422);
        }
        $config = self::mediaConfig($model);
        $size = $payload['size'] ?? ($config['sizes'][0] ?? '1024x1024');
        if (! is_string($size) || ! isset(self::SIZE_MAP[$size])) {
            throw new AiProxyException('The selected image size is not supported by this Kinovi model.', 422);
        }
        [$aspectRatio, $resolution] = self::SIZE_MAP[$size];

        return [
            'model' => $model,
            'inputs' => [
                'prompt' => self::prompt($payload),
                'aspectRatio' => $aspectRatio,
                'resolution' => $resolution,
            ],
            'autoFix' => true,
        ];
    }

    /** Parse a successful recordInfo response into the shared image response shape. */
    public static function imageResponse(array $data): array
    {
        $output = $data['output'] ?? null;
        if (! is_array($output) || $output === []) {
            throw new AiProxyException('The Kinovi image provider did not return a usable image.', 502);
        }
        $images = [];
        foreach ($output as $item) {
            $url = is_array($item) ? ($item['url'] ?? null) : null;
            if (! is_string($url) || ! GeneratedImageStore::validResultUrl($url)) {
                throw new AiProxyException('The Kinovi image provider returned an invalid image.', 502);
            }
            $images[] = ['url' => $url];
        }

        return ['data' => $images];
    }

    /** Catalog rows for sync/check, shaped like AiProviderTransport::catalog output. */
    public static function catalog(): array
    {
        $models = [];
        foreach (self::MODELS as $id => $category) {
            $models[] = [
                'id' => $id,
                'name' => self::NAMES[$id] ?? $id,
                'category' => $category,
                'capabilities' => [$category],
                'input_modalities' => ['text'],
                'output_modalities' => [$category],
            ];
        }

        return $models;
    }

    private static function prompt(array $payload): string
    {
        $prompt = $payload['prompt'] ?? null;
        if (! is_string($prompt) || trim($prompt) === '' || mb_strlen($prompt) > 4000) {
            throw new AiProxyException('The Kinovi image prompt is invalid.', 422);
        }

        return trim($prompt);
    }
}
