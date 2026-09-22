<?php

namespace App\Services;

use App\Media\CapabilityInput;
use App\Media\CapabilityParam;
use App\Media\CapabilityResolver;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\OutputKind;
use App\Media\MediaCapability;
use App\Models\AiModelProfile;
use App\Models\User;
use InvalidArgumentException;

final class MediaModelConfig
{
    public static function forModel(AiModelProfile $model): array
    {
        if (ThreeDProtocol::supports($model)) {
            return ThreeDProtocol::config();
        }
        if ($model->provider?->protocol === 'fal' && $model->category === 'image') {
            $published = self::publishedImageConfig($model);
            if ($published !== null) {
                return $published;
            }
        }
        if ($model->provider?->protocol === 'fal') {
            return FalProtocol::mediaConfig($model->upstream_model_id ?: $model->model_id)
                ?? throw new InvalidArgumentException('This fal media model is not supported.');
        }
        if ($model->provider?->protocol === 'kinovi') {
            return KinoviProtocol::mediaConfig($model->upstream_model_id ?: $model->model_id)
                ?? throw new InvalidArgumentException('This Kinovi media model is not supported.');
        }
        $id = strtolower($model->upstream_model_id ?: $model->model_id);
        $gptImage = preg_match('/(?:^|\/)gpt-image(?:-|$)/', $id) === 1;
        $geminiImage = str_contains($id, 'gemini-') && str_contains($id, '-image');
        $seedance = str_contains($id, 'seedance-');
        $defaults = [
            'image_path' => 'images/generations',
            'video_path' => 'videos/generations',
            'video_status_path' => 'videos/generations/{id}',
            'sizes' => $gptImage ? ['auto'] : ['1024x1024', '1024x1792', '1792x1024'],
            'durations' => $seedance ? [] : [5, 10],
            'aspect_ratios' => $seedance ? [] : ['16:9', '9:16'],
            'max_quantity' => 4,
            'supports_size' => ! $gptImage,
            'supports_n' => ! $gptImage && ! $geminiImage,
            'supports_duration' => ! $seedance,
            'supports_aspect_ratio' => ! $seedance,
        ];

        return array_replace($defaults, array_intersect_key($model->generation_config ?? [], $defaults), [
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
        ]);
    }

    public static function hasCatalogImage(AiModelProfile $model): bool
    {
        return $model->provider?->protocol === 'fal' && $model->category === 'image'
            && $model->capabilityRevisions()->where('status', 'published')->whereNotNull('source_schema')->exists();
    }

    private static function publishedImageConfig(AiModelProfile $model): ?array
    {
        $revisions = $model->capabilityRevisions()->where('status', 'published')->where('provider_bindings->adapter', 'fal_image_v1')->get();
        if ($revisions->isEmpty()) {
            return null;
        }
        $first = MediaCapability::fromArray($revisions->first()->definition);
        $size = $first->param('size');
        $reference = $revisions->contains('operation', 'image_edit');

        return [
            'image_path' => $model->upstream_model_id ?: $model->model_id,
            'video_path' => null, 'video_status_path' => null,
            'sizes' => $size?->options ?? [], 'durations' => [], 'aspect_ratios' => [],
            'max_quantity' => 1, 'supports_size' => $size !== null, 'supports_n' => false,
            'supports_duration' => false, 'supports_aspect_ratio' => false, 'supports_pro' => false,
            'supports_reference_image' => $reference,
            'reference_required' => $reference && ! $revisions->contains('operation', 'text_to_image'),
            'reference_model' => null, 'audio_path' => null, 'audio_status_path' => null, 'audio_kind' => null,
            'voices' => [], 'speed_min' => null, 'speed_max' => null, 'speed_default' => null,
            'duration_min' => null, 'duration_max' => null, 'duration_default' => null, 'max_characters' => null,
            'price_unit' => 'generation',
        ];
    }

    /**
     * Derive the effective MediaCapability per operation from existing model config,
     * for models not yet migrated to a stored revision.
     *
     * @return array<string, MediaCapability>
     */
    public static function deriveCapabilities(AiModelProfile $model): array
    {
        if (ThreeDProtocol::supports($model)) {
            return ThreeDProtocol::capabilities($model->model_id);
        }
        // Legacy fallback is deliberately restricted to the existing curated integrations.
        $config = $model->provider?->protocol === 'fal'
            ? FalProtocol::mediaConfig($model->upstream_model_id ?: $model->model_id)
            : self::forModel($model);
        if ($config === null) {
            return [];
        }
        $public = $model->model_id;
        $prompt = new CapabilityInput('prompt', InputRole::Prompt, 'string', required: true);
        $sizeParams = ($config['supports_size'] && ($config['sizes'] ?? []) !== [])
            ? [new CapabilityParam('size', 'enum', default: $config['sizes'][0], options: array_values($config['sizes']))]
            : [];

        return match ($model->category) {
            'image' => array_filter([
                MediaOperation::TextToImage->value => new MediaCapability(
                    $public, MediaOperation::TextToImage, OutputKind::Image, 1, [$prompt], $sizeParams,
                ),
                // image_edit is offered only for models whose provider API accepts a reference image.
                MediaOperation::ImageEdit->value => ($config['supports_reference_image'] ?? false)
                    ? new MediaCapability(
                        $public, MediaOperation::ImageEdit, OutputKind::Image, 1,
                        [$prompt, new CapabilityInput('reference_image', InputRole::ImageRef, 'asset', single: true, max: 1, required: true)],
                        $sizeParams,
                    )
                    : null,
            ]),
            'video' => self::deriveVideoCapabilities($config, $public, $prompt),
            'audio' => self::deriveAudioCapabilities($config, $public, $prompt),
            'avatar' => self::deriveAvatarCapabilities($config, $public),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, MediaCapability>
     */
    private static function deriveAudioCapabilities(array $config, string $public, CapabilityInput $prompt): array
    {
        $kind = $config['audio_kind'] ?? null;
        if ($kind === 'speech') {
            $params = [];
            $voices = array_column($config['voices'] ?? [], 'id');
            if ($voices !== []) {
                $params[] = new CapabilityParam('voice', 'enum', default: $voices[0], options: $voices);
            }
            if (($config['speed_default'] ?? null) !== null) {
                $params[] = new CapabilityParam('speed', 'number', default: $config['speed_default'], min: $config['speed_min'], max: $config['speed_max']);
            }

            return [MediaOperation::TextToSpeech->value => new MediaCapability($public, MediaOperation::TextToSpeech, OutputKind::Audio, 1, [$prompt], $params)];
        }
        if ($kind === 'music') {
            $params = [];
            if (($config['duration_default'] ?? null) !== null) {
                $params[] = new CapabilityParam('duration', 'number', default: $config['duration_default'], min: $config['duration_min'], max: $config['duration_max'], unit: 's');
            }
            // Tempo is prompt-level guidance the pipeline appends for any music model
            // (legacy parity), so it is part of the contract even without a native field.
            $params[] = new CapabilityParam('tempo', 'integer', min: 40, max: 200, unit: 'BPM');
            if ($config['supports_instrumental'] ?? false) {
                $params[] = new CapabilityParam('instrumental', 'boolean', default: false, options: [false, true]);
            }
            if ($config['supports_custom_lyrics'] ?? false) {
                $params[] = new CapabilityParam('custom', 'boolean', default: false, options: [false, true]);
            }

            return [MediaOperation::Music->value => new MediaCapability($public, MediaOperation::Music, OutputKind::Audio, 1, [$prompt], $params)];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, MediaCapability>
     */
    private static function deriveVideoCapabilities(array $config, string $public, CapabilityInput $prompt): array
    {
        $aspect = ($config['supports_aspect_ratio'] && ($config['aspect_ratios'] ?? []) !== [])
            ? new CapabilityParam('aspect_ratio', 'enum', default: $config['aspect_ratios'][0], options: array_values($config['aspect_ratios']))
            : null;
        $duration = ($config['supports_duration'] && ($config['durations'] ?? []) !== [])
            ? new CapabilityParam('duration', 'enum', default: $config['durations'][0], options: array_values($config['durations']), unit: 's')
            : null;
        // Quantity and Pro are model-bounded, so they belong in the contract: the validator
        // enforces the model's own ceiling and the coordinator prices off the declared values.
        $maxQuantity = max(1, (int) ($config['max_quantity'] ?? 1));
        $count = $maxQuantity > 1
            ? new CapabilityParam('count', 'integer', default: 1, min: 1, max: $maxQuantity)
            : null;
        $pro = ($config['supports_pro'] ?? false)
            ? new CapabilityParam('pro', 'boolean', default: false, options: [false, true])
            : null;

        return array_filter([
            // Text-to-video: prompt + aspect ratio + duration.
            MediaOperation::TextToVideo->value => new MediaCapability(
                $public, MediaOperation::TextToVideo, OutputKind::Video, 1, [$prompt],
                array_values(array_filter([$aspect, $duration, $count, $pro])),
            ),
            // Image-to-video is offered only when the provider accepts a reference image. The
            // reference frame fixes the geometry, so aspect ratio is not a parameter here.
            MediaOperation::ImageToVideo->value => ($config['supports_reference_image'] ?? false)
                ? new MediaCapability(
                    $public, MediaOperation::ImageToVideo, OutputKind::Video, 1,
                    [$prompt, new CapabilityInput('reference_image', InputRole::ImageRef, 'asset', single: true, max: 1, required: true)],
                    array_values(array_filter([$duration, $count, $pro])),
                )
                : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, MediaCapability>
     */
    private static function deriveAvatarCapabilities(array $config, string $public): array
    {
        $durations = $config['durations'] ?? [];
        if (! ($config['supports_duration'] ?? false) || $durations === []) {
            return [];
        }
        $duration = new CapabilityParam(
            'duration', 'integer',
            default: $config['duration_default'] ?? (in_array(5, $durations, true) ? 5 : $durations[0]),
            min: $config['duration_min'] ?? min($durations),
            max: $config['duration_max'] ?? max($durations),
            unit: 's',
        );
        $aspect = ($config['supports_aspect_ratio'] && ($config['aspect_ratios'] ?? []) !== [])
            ? new CapabilityParam('aspect_ratio', 'enum', default: $config['aspect_ratios'][0], options: array_values($config['aspect_ratios']))
            : null;

        return [
            MediaOperation::TalkingAvatar->value => new MediaCapability(
                $public, MediaOperation::TalkingAvatar, OutputKind::Video, 1,
                [
                    new CapabilityInput('avatar_photo', InputRole::AvatarPhoto, 'asset', required: true),
                    new CapabilityInput('speech_audio', InputRole::SpeechAudio, 'asset', required: true),
                    new CapabilityInput('prompt', InputRole::Prompt, 'string', required: $config['prompt_required'] ?? false),
                ],
                array_values(array_filter([$duration, $aspect])),
            ),
        ];
    }

    public static function catalogModel(array $model): array
    {
        $kind = $model['category'] ?? $model['kind'] ?? null;
        if (! is_string($kind) || $kind === '') {
            $id = strtolower((string) ($model['id'] ?? ''));
            $kind = match (true) {
                isset(FalProtocol::MEDIA_MODELS[$id]) => FalProtocol::MEDIA_MODELS[$id],
                $id === ThreeDProtocol::MODEL => 'model3d',
                preg_match('/(?:^|\/)(?:gpt-image-|dall-e-)/', $id) === 1,
                str_contains($id, 'gemini-') && str_contains($id, '-image') => 'image',
                preg_match('/(?:^|\/)(?:seedance-|sora-|veo-)/', $id) === 1 => 'video',
                preg_match('/(?:^|\/)(?:text-embedding-|bge-|embedding-)/', $id) === 1 => 'embedding',
                default => 'chat',
            };
        }
        $kind = match (strtolower($kind)) {
            'text-to-audio', 'text-to-speech' => 'audio',
            'text-to-video', 'image-to-video' => 'video',
            'text-to-image', 'image-to-image' => 'image',
            'text-to-3d', 'image-to-3d' => 'model3d',
            default => $kind,
        };
        $model['category'] = $kind;
        if (! isset($model['output_modalities'])) {
            $model['output_modalities'] = match ($kind) {
                'image', 'images' => ['image'],
                'video', 'avatar' => ['video'],
                'audio' => ['audio'],
                default => ['text'],
            };
        }

        return $model;
    }

    public static function allowedFor(User $user, AiModelProfile $model): bool
    {
        if (! $model->is_enabled || ! $model->is_available || ! $model->provider?->is_enabled) {
            return false;
        }
        if ($model->provider->protocol === 'fal'
            && ! ThreeDProtocol::supports($model) && ! self::hasCatalogImage($model)
            && (FalProtocol::MEDIA_MODELS[$model->upstream_model_id ?: $model->model_id] ?? null) !== $model->category) {
            return false;
        }
        if ($model->provider->protocol === 'kinovi'
            && (KinoviProtocol::MODELS[$model->upstream_model_id ?: $model->model_id] ?? null) !== $model->category) {
            return false;
        }

        return app(CapabilityResolver::class)->operations($model) !== [];
    }

    public static function publicModel(AiModelProfile $model): array
    {
        $config = self::forModel($model);

        return [
            'id' => $model->model_id, 'name' => $model->display_name,
            'operations' => app(CapabilityResolver::class)->operations($model),
            'category' => $model->category,
            'capabilities' => $model->capabilities ?? [],
            'token_cost' => $model->token_cost,
            'billing_mode' => 'tokens',
            'price_unit' => $config['price_unit'] ?? 'generation',
            ...(isset($config['avatar_audio_mode']) ? ['avatar_audio_mode' => $config['avatar_audio_mode']] : []),
            'sizes' => $config['sizes'], 'durations' => $config['durations'],
            'aspect_ratios' => $config['aspect_ratios'], 'max_quantity' => $config['max_quantity'],
            'pro' => [
                'supported' => $config['supports_pro'],
                'multiplier' => 2,
                'description' => $config['supports_pro'] ? '16 inference steps and maximum encoding quality.' : null,
            ],
            'reference_image' => [
                'supported' => $config['supports_reference_image'],
                'required' => $config['reference_required'],
                'max_bytes' => $config['reference_image_max_bytes'] ?? 10 * 1024 * 1024,
                'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
                'aspect_ratio_from_image' => $config['supports_reference_image'],
            ],
            'audio' => $config['audio_kind'] === null ? null : [
                'kind' => $config['audio_kind'],
                'voices' => $config['voices'],
                'speed' => $config['speed_min'] === null ? null : [
                    'min' => $config['speed_min'], 'max' => $config['speed_max'], 'default' => $config['speed_default'],
                ],
                'duration' => $config['duration_min'] === null ? null : [
                    'min' => $config['duration_min'], 'max' => $config['duration_max'], 'default' => $config['duration_default'],
                ],
                'max_characters' => $config['max_characters'],
                'instrumental' => (bool) ($config['supports_instrumental'] ?? false),
                'custom_lyrics' => (bool) ($config['supports_custom_lyrics'] ?? false),
            ],
        ];
    }

    public static function path(string $path, ?string $taskId = null): string
    {
        if ($taskId !== null) {
            if (substr_count($path, '{id}') !== 1 || $taskId === '' || strlen($taskId) > 255) {
                throw new InvalidArgumentException('Invalid video status path.');
            }
            $path = str_replace('{id}', rawurlencode($taskId), $path);
        }
        if ($path === '' || strlen($path) > 512 || str_starts_with($path, '/')
            || preg_match('/[\x00-\x20\x7f\\\\?#{}]/', $path)
            || str_contains(rawurldecode($path), '..') || str_contains($path, '://')
            || preg_match('/^[A-Za-z0-9._~!$&\x27()*+,;=:@%\/-]+$/D', $path) !== 1) {
            throw new InvalidArgumentException('Enter a relative provider API path.');
        }

        return $path;
    }
}
