<?php

namespace App\Services;

use App\Exceptions\AiProxyException;

/**
 * Kinovi (kinovi.ai) media-generation adapter. Kinovi is a task-based API:
 * POST /api/v1/jobs/createTask returns {taskId}; GET /api/v1/jobs/recordInfo?taskId=
 * returns {status: waiting|generating|success|fail, output:[{url,...}]}. It is NOT
 * OpenAI-compatible, so it gets its own protocol like fal.
 *
 * Every Kinovi job is asynchronous and slow (image ~60-90s, video minutes), so image,
 * video, and audio all run through the queued submit/poll pipelines rather than a
 * blocking HTTP request. Curated image, video, talking-avatar and prompt-only Suno
 * integrations are exposed only with explicit input contracts. Edit/extend/remix
 * variants remain excluded until their integration is verified.
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
        // Curated text-to-video routes; reference/edit/extend variants need separate handling.
        // Prior createTask probes required a first frame for happyhorse1.0 and reference
        // images for high-dynamic/cinematic; MiniMax text-to-video returned "Unknown model".
        // HappyHorse's published t2v default conflicts with that probe. Keep these routes
        // excluded until their distinct request contracts and account access are verified.
        'seedance2-5' => 'video',
        'seedance-20' => 'video',
        'seedance2-fast' => 'video',
        'seedance2.0-mini' => 'video',
        'wan3.0-text-to-video' => 'video',
        'wan3.0-prime-text-to-video' => 'video',
        // Audio (prompt-only Suno). Music returns two tracks stored under one billed job;
        // suno-sounds writes short SFX/ambience clips.
        // suno-remix / suno-sample need uploaded input audio and stay excluded.
        'suno-music' => 'audio',
        'suno-sounds' => 'audio',
        'minimax-h3-turbo-avatar-talking' => 'avatar',
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
        'suno-music' => 'Suno Music',
        'suno-sounds' => 'Suno Sounds',
        'minimax-h3-turbo-avatar-talking' => 'MiniMax H3 Avatar',
    ];

    // App image sizes map to Kinovi's aspectRatio; resolution stays the cheapest tier (1k)
    // and autoFix bumps it to a model's floor (e.g. NanoBanana 2 has no 1k tier).
    private const SIZE_MAP = [
        '1024x1024' => '1:1',
        '1024x1792' => '9:16',
        '1792x1024' => '16:9',
    ];

    // Kinovi image models whose API accepts reference images (inputs.uploadedUrls, up to 10),
    // per kinovi.ai/models/gpt-image-2. Enables the image_edit operation for these models.
    private const REFERENCE_IMAGE = ['gpt-image-2', 'gpt-image-2.5-sunburst', 'gpt-image-2.5-flare'];

    public const REFERENCE_IMAGE_MAX = 10;

    // Video framing the app exposes; Kinovi accepts these labels on every text-to-video model.
    private const VIDEO_ASPECT_RATIOS = ['16:9', '9:16', '1:1', '4:3', '3:4'];

    // Discrete durations within Kinovi's 2-15s window. Output tier stays at the cheapest tier
    // Kinovi still accepts: it now rejects 480p with HTTP 400 "Invalid enum value. Expected
    // '720p' | '1080p'", which broke submission for every Kinovi text-to-video model.
    private const VIDEO_DURATIONS = [5, 8, 10];

    private const VIDEO_RESOLUTION = '720p';

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
                'supports_reference_image' => in_array($model, self::REFERENCE_IMAGE, true),
            ];
        }

        if ($category === 'audio') {
            $music = $model === 'suno-music';

            return [
                ...$base,
                'audio_path' => $model,
                'price_unit' => 'request',
                'audio_status_path' => $model,
                // Both are prompt-driven composition, so the studio's "music" experience fits;
                // Suno exposes no duration control (length follows the composition).
                'audio_kind' => 'music',
                'max_characters' => $music ? 2500 : 500,
                'supports_instrumental' => $music,
                'supports_custom_lyrics' => $music,
            ];
        }

        if ($category === 'avatar') {
            return [
                ...$base,
                'durations' => range(2, 15),
                'aspect_ratios' => self::VIDEO_ASPECT_RATIOS,
                'supports_duration' => true,
                'supports_aspect_ratio' => true,
                'price_unit' => 'second',
            ];
        }

        // video
        return [
            ...$base,
            'price_unit' => 'second',
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

        $inputs = [
            'prompt' => self::prompt($payload),
            'aspectRatio' => self::SIZE_MAP[$size],
            'resolution' => '1k',
        ];
        $uploaded = $payload['uploadedUrls'] ?? null;
        if (is_array($uploaded)) {
            $urls = array_values(array_filter($uploaded, static fn ($url): bool => is_string($url) && $url !== ''));
            if ($urls !== []) {
                $inputs['uploadedUrls'] = array_slice($urls, 0, self::REFERENCE_IMAGE_MAX);
            }
        }

        return ['model' => $model, 'inputs' => $inputs, 'autoFix' => true];
    }

    /** Build the createTask body for a text-to-video request. */
    public static function videoTask(array $payload): array
    {
        if (($payload['model'] ?? null) === 'minimax-h3-turbo-avatar-talking') {
            return self::avatarTask($payload);
        }
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

    /** Both URLs refer to verified provider uploads of owned assets. */
    private static function avatarTask(array $payload): array
    {
        foreach (['image_url', 'audio_url'] as $key) {
            if (! is_string($payload[$key] ?? null) || ! filter_var($payload[$key], FILTER_VALIDATE_URL)) {
                throw new AiProxyException('A portrait and speech audio are required.', 422);
            }
        }
        $duration = $payload['duration'] ?? 5;
        $aspect = $payload['aspect_ratio'] ?? '16:9';
        if (! is_int($duration) || $duration < 2 || $duration > 15 || ! in_array($aspect, self::VIDEO_ASPECT_RATIOS, true)) {
            throw new AiProxyException('The avatar duration or aspect ratio is invalid.', 422);
        }

        return [
            'model' => $payload['model'], 'autoFix' => false,
            'inputs' => [
                'imageUrls' => [$payload['image_url']], 'audioUrls' => [$payload['audio_url']],
                'prompt' => (string) ($payload['prompt'] ?? ''),
                'duration' => $duration, 'aspectRatio' => $aspect,
                // Kinovi's schema binding uses megapixel values rather than its display label.
                'outputResolution' => '0.2',
            ],
        ];
    }

    /** Build the createTask body for a prompt-only audio request (Suno). */
    public static function audioTask(array $payload): array
    {
        $model = $payload['model'] ?? null;
        if (! is_string($model) || (self::MODELS[$model] ?? null) !== 'audio') {
            throw new AiProxyException('This Kinovi audio model is not supported.', 422);
        }
        $inputs = ['prompt' => self::prompt($payload)];
        if ($model === 'suno-music') {
            // custom=true means the prompt IS the lyrics; instrumental=true drops vocals.
            if (array_key_exists('custom', $payload)) {
                $inputs['custom'] = (bool) $payload['custom'];
            }
            if (array_key_exists('instrumental', $payload)) {
                $inputs['instrumental'] = (bool) $payload['instrumental'];
            }
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
