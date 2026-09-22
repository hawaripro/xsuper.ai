<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Media\CapabilityInput;
use App\Media\CapabilityParam;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\OutputKind;
use App\Media\MediaCapability;
use App\Models\AiModelProfile;

/** Curated image-to-GLB contract; public source evidence is not account/execution verification. */
final class ThreeDProtocol
{
    public const MODEL = 'fal-ai/trellis-2';

    public const REQUEST_PATH = 'fal-ai/trellis-2/requests/{id}';

    public const FIXED_PARAMS = ['resolution' => 512, 'decimation_target' => 50000, 'texture_size' => 1024];

    public static function supports(AiModelProfile $model): bool
    {
        return $model->category === 'model3d' && $model->provider?->protocol === 'fal'
            && ($model->upstream_model_id ?: $model->model_id) === self::MODEL;
    }

    public static function config(): array
    {
        return [
            'model3d_path' => self::MODEL, 'model3d_status_path' => self::REQUEST_PATH,
            'operations' => [MediaOperation::ImageTo3d->value], 'output_kind' => 'model3d', 'format' => 'glb',
            'input_mapping' => ['image_ref' => 'image_url'], 'fixed_params' => self::FIXED_PARAMS,
            'price_unit' => 'generation', 'provider_cost_usd' => 0.25,
            'sizes' => [], 'durations' => [], 'aspect_ratios' => [], 'max_quantity' => 1,
            'supports_size' => false, 'supports_n' => false, 'supports_duration' => false,
            'supports_aspect_ratio' => false, 'supports_pro' => false,
            'supports_reference_image' => true, 'reference_required' => true, 'reference_model' => null,
            'audio_kind' => null, 'audio_path' => null, 'audio_status_path' => null,
            'voices' => [], 'speed_min' => null, 'speed_max' => null, 'speed_default' => null,
            'duration_min' => null, 'duration_max' => null, 'duration_default' => null, 'max_characters' => null,
        ];
    }

    public static function capabilities(string $public): array
    {
        return [MediaOperation::ImageTo3d->value => self::curatedCapability($public)];
    }

    public static function curatedCapability(string $public): MediaCapability
    {
        return new MediaCapability($public, MediaOperation::ImageTo3d, OutputKind::Model3d, 1,
            [new CapabilityInput('image_ref', InputRole::ImageRef, 'asset', required: true)],
            [new CapabilityParam('seed', 'integer')]);
    }

    public static function compatible(MediaCapability $capability): bool
    {
        return $capability->toArray() === self::curatedCapability($capability->modelPublicId)->toArray();
    }

    public static function request(string $model, string $imageDataUri, array $settings): array
    {
        if ($model !== self::MODEL || ! preg_match('#^data:image/(?:png|jpeg|webp);base64,#', $imageDataUri)
            || ($settings['resolution'] ?? null) !== 512 || ($settings['decimation_target'] ?? null) !== 50000
            || ($settings['texture_size'] ?? null) !== 1024
            || array_diff_key($settings, [...self::FIXED_PARAMS, 'seed' => null]) !== []
            || (isset($settings['seed']) && ! is_int($settings['seed']))) {
            throw new AiProxyException('The 3D generation configuration is invalid.', 502);
        }

        return ['image_url' => $imageDataUri, ...$settings];
    }
}
