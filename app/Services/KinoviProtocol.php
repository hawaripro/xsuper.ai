<?php

namespace App\Services;

use App\Exceptions\AiProxyException;

/**
 * Kinovi (kinovi.ai) media-generation adapter. Kinovi is a task-based API:
 * POST /api/v1/jobs/createTask returns {taskId}; GET /api/v1/jobs/recordInfo?taskId=
 * returns {status: waiting|generating|success|fail, output:[{url,...}]}. It is NOT
 * OpenAI-compatible, so it gets its own protocol like fal.
 *
 * Every Kinovi job is asynchronous and slow (image ~60-90s, video minutes), so both
 * image and video run through the queued submit/poll pipelines rather than a blocking
 * HTTP request. Text-to-image and text-to-video are supported here; models that require
 * uploaded reference media (image-to-video, edit/extend, talking avatar) and the Suno
 * audio models are intentionally excluded until reference-upload and a prompt-only audio
 * kind exist, so no member ever sees a model that cannot complete.
 */
final class KinoviProtocol
{
    /** Curated public model id => category. */
    public const MODELS = [
        // Image (text-to-image). autoFix lets Kinovi coerce resolution to each model's floor.
        'gpt-image-2' => 'image',
        'gpt-image-2.5-sunburst' => 'image',
        'gpt-image-2.5-flare' => 'image',
        'seedream-5.0-pro' => 'image',
        'nanobanana-pro' => 'image',
        'nanobanana2' => 'image',
        'midjourney-v8' => 'image',
        'midjourney-v7-niji' => 'image',
        // Video (text-to-video). Reference/edit/extend/avatar variants need uploaded media.
        'seedance2-5' => 'video',
        'seedance-20' => 'video',
        'seedance2-fast' => 'video',
        'seedance2.0-mini' => 'video',
        'wan3.0-text-to-video' => 'video',
        'wan3.0-prime-text-to-video' => 'video',
        'happyhorse1.0' => 'video',
        'minimax-h3-turbo-text-to-video' => 'video',
        'minimax-h3-turbo-high-dynamic' => 'video',
        'minimax-h3-turbo-cinematic' => 'video',
    ];

    public const NAMES = [
        'gpt-image-2' => 'GPT Image 2',
        'gpt-image-2.5-sunburst' => 'GPT Image 2.5 Sunburst',
        'gpt-image-2.5-flare' => 'GPT Image 2.5 Flare',
        'seedream-5.0-pro' => 'Seedream 5.0 Pro',
        'nanobanana-pro' => 'Nano Banana Pro',
        'nanobanana2' => 'NanoBanana 2',
        'midjourney-v8' => 'Midjourney V8',
        'midjourney-v7-niji' => 'Midjourney Niji V7',
        'seedance2-5' => 'Seedance 2.5',
        'seedance-20' => 'Seedance 2.0',
        'seedance2-fast' => 'Seedance 2.0 Fast',
        'seedance2.0-mini' => 'Seedance 2.0 Mini',
        'wan3.0-text-to-video' => 'Wan 3.0 Text-to-Video',
        'wan3.0-prime-text-to-video' => 'Wan 3.0 Prime Text-to-Video',
        'happyhorse1.0' => 'HappyHorse 1.0',
        'minimax-h3-turbo-text-to-video' => 'MiniMax H3 Turbo Text-to-Video',
        'minimax-h3-turbo-high-dynamic' => 'MiniMax H3 Turbo High Dynamic',
        'minimax-h3-turbo-cinematic' => 'MiniMax H3 Turbo Cinematic',
    ];

    // App image sizes map to Kinovi's aspectRatio; resolution stays the cheapest tier (1k)
    // and autoFix bumps it to a model's floor (e.g. NanoBanana 2 has no 1k tier).
    private const SIZE_MAP = [
        '1024x1024' => '1:1',
        '1024x1792' => '9:16',
        '1792x1024' => '16:9',
    ];

    // Video framing the app exposes; Kinovi accepts these labels on every text-to-video model.
    private const VIDEO_ASPECT_RATIOS = ['16:9', '9:16', '1:1', '4:3', '3:4'];

    // Discrete durations within Kinovi's 2-15s window. Output tier is fixed at the cheapest (480p).
    private const VIDEO_DURATIONS = [5, 8, 10];

    private const VIDEO_RESOLUTION = '480p';

    public static function mediaConfig(string $model): ?array
    {
        $category = self::MODELS[$model] ?? null;
        if ($category === null) {
            return null;
        }

        $base = [
            'image_path' => $model,
            'video_path' => $model,
            'video_status_path' => $model,
            'sizes' => [],
            'durations' => [],
            'aspect_ratios' => [],
            'max_quantity' => 1,
            'supports_size' => false,
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

        if ($category === 'image') {
            return [
                ...$base,
                'sizes' => array_keys(self::SIZE_MAP),
                // One createTask yields one job; midjourney models still return four variations in it.
                'max_quantity' => 1,
                'supports_size' => true,
                'supports_n' => false,
            ];
        }

        // video
        return [
            ...$base,
            'durations' => self::VIDEO_DURATIONS,
            'aspect_ratios' => self::VIDEO_ASPECT_RATIOS,
            'max_quantity' => 2,
            'supports_duration' => true,
            'supports_aspect_ratio' => true,
        ];
    }

    /** Build the createTask body for a text-to-image request. */
    public static function imageTask(array $payload): array
    {
        $model = $payload['model'] ?? null;
        if (! is_string($model) || (self::MODELS[$model] ?? null) !== 'image') {
            throw new AiProxyException('This Kinovi image model is not supported.', 422);
        }
        $size = $payload['size'] ?? '1024x1024';
        if (! is_string($size) || ! isset(self::SIZE_MAP[$size])) {
            throw new AiProxyException('The selected image size is not supported by this Kinovi model.', 422);
        }

        return [
            'model' => $model,
            'inputs' => [
                'prompt' => self::prompt($payload),
                'aspectRatio' => self::SIZE_MAP[$size],
                'resolution' => '1k',
            ],
            'autoFix' => true,
        ];
    }

    /** Build the createTask body for a text-to-video request. */
    public static function videoTask(array $payload): array
    {
        $model = $payload['model'] ?? null;
        if (! is_string($model) || (self::MODELS[$model] ?? null) !== 'video') {
            throw new AiProxyException('This Kinovi video model is not supported.', 422);
        }
        if (isset($payload['image_url'])) {
            throw new AiProxyException('Reference images are not supported by this Kinovi video model.', 422);
        }
        $inputs = [
            'prompt' => self::prompt($payload),
            'outputResolution' => self::VIDEO_RESOLUTION,
        ];
        $aspect = $payload['aspect_ratio'] ?? null;
        if (is_string($aspect) && in_array($aspect, self::VIDEO_ASPECT_RATIOS, true)) {
            $inputs['aspectRatio'] = $aspect;
        }
        $duration = $payload['duration'] ?? null;
        if (is_numeric($duration) && (int) $duration > 0) {
            $inputs['duration'] = (int) $duration;
        }

        return ['model' => $model, 'inputs' => $inputs, 'autoFix' => true];
    }

    /**
     * Extract validated public asset URLs from a successful recordInfo response.
     *
     * @return array<int, string>
     */
    public static function outputUrls(array $data): array
    {
        $output = $data['output'] ?? null;
        if (! is_array($output) || $output === []) {
            throw new AiProxyException('The Kinovi provider did not return a usable result.', 502);
        }
        $urls = [];
        foreach ($output as $item) {
            $url = is_array($item) ? ($item['url'] ?? null) : (is_string($item) ? $item : null);
            if (! is_string($url) || ! GeneratedImageStore::validResultUrl($url)) {
                throw new AiProxyException('The Kinovi provider returned an invalid result URL.', 502);
            }
            $urls[] = $url;
        }

        return $urls;
    }

    /** Parse a successful recordInfo response into the shared sync image response shape. */
    public static function imageResponse(array $data): array
    {
        return ['data' => array_map(static fn (string $url): array => ['url' => $url], self::outputUrls($data))];
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
            throw new AiProxyException('The Kinovi prompt is invalid.', 422);
        }

        return trim($prompt);
    }
}
