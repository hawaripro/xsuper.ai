<?php

namespace App\Services;

use App\Models\AiModelProfile;
use App\Models\User;
use InvalidArgumentException;

final class MediaModelConfig
{
    public static function forModel(AiModelProfile $model): array
    {
        if ($model->provider?->protocol === 'fal') {
            return FalProtocol::mediaConfig($model->upstream_model_id ?: $model->model_id)
                ?? throw new InvalidArgumentException('This fal media model is not supported.');
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

    public static function catalogModel(array $model): array
    {
        $kind = $model['category'] ?? $model['kind'] ?? null;
        if (! is_string($kind) || $kind === '') {
            $id = strtolower((string) ($model['id'] ?? ''));
            $kind = match (true) {
                isset(FalProtocol::MEDIA_MODELS[$id]) => FalProtocol::MEDIA_MODELS[$id],
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
            'text-to-image' => 'image',
            default => $kind,
        };
        $model['category'] = $kind;
        if (! isset($model['output_modalities'])) {
            $model['output_modalities'] = match ($kind) {
                'image', 'images' => ['image'],
                'video' => ['video'],
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
        if ($model->category === 'audio' && $model->provider->protocol !== 'fal') {
            return false;
        }
        if ($model->provider->protocol === 'fal'
            && (FalProtocol::MEDIA_MODELS[$model->upstream_model_id ?: $model->model_id] ?? null) !== $model->category) {
            return false;
        }
        return true;
    }

    public static function publicModel(AiModelProfile $model): array
    {
        $config = self::forModel($model);

        return [
            'id' => $model->model_id, 'name' => $model->display_name,
            'provider' => $model->provider_name ?: $model->provider?->name,
            'category' => $model->category,
            'capabilities' => $model->capabilities ?? [],
            'token_cost' => $model->token_cost,
            'billing_mode' => 'tokens',
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
                'max_bytes' => 10 * 1024 * 1024,
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
