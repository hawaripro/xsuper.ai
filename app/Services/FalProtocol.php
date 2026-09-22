<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use Illuminate\Support\Str;

final class FalProtocol
{
    public const IMAGE_SCHNELL = 'fal-ai/flux/schnell';

    public const IMAGE_PRO = 'fal-ai/flux-2-pro';

    // Cheap text-to-image endpoints that share the flux `image_size` schema.
    public const IMAGE_DEV = 'fal-ai/flux/dev';

    public const IMAGE_SDXL = 'fal-ai/fast-sdxl';

    public const IMAGE_SANA = 'fal-ai/sana';

    public const VIDEO = 'fal-ai/longcat-video/distilled/text-to-video/480p';

    public const VIDEO_REFERENCE = 'fal-ai/longcat-video/distilled/image-to-video/480p';

    public const VIDEO_REQUEST_PATH = 'fal-ai/longcat-video/requests/{id}';

    public const AVATAR = 'minimax/h3-max-turbo/image-to-video';

    public const AVATAR_REQUEST_PATH = 'minimax/h3-max-turbo/requests/{id}';

    public const AUDIO_SPEECH = 'fal-ai/kokoro/american-english';

    public const AUDIO_MUSIC = 'fal-ai/stable-audio-25/text-to-audio';

    public const AUDIO_SPEECH_REQUEST_PATH = 'fal-ai/kokoro/requests/{id}';

    public const AUDIO_MUSIC_REQUEST_PATH = 'fal-ai/stable-audio-25/requests/{id}';

    public const ROUTER = 'openrouter/router';

    public const CHAT_MODEL = 'google/gemini-2.5-flash-lite';

    public const MEDIA_MODELS = [
        self::IMAGE_SCHNELL => 'image',
        self::IMAGE_PRO => 'image',
        self::IMAGE_DEV => 'image',
        self::IMAGE_SDXL => 'image',
        self::IMAGE_SANA => 'image',
        self::VIDEO => 'video',
        self::VIDEO_REFERENCE => 'video',
        self::AVATAR => 'avatar',
        self::AUDIO_SPEECH => 'audio',
        self::AUDIO_MUSIC => 'audio',
    ];

    public static function mediaConfig(string $model): ?array
    {
        if (! isset(self::MEDIA_MODELS[$model])) {
            return null;
        }
        $image = self::MEDIA_MODELS[$model] === 'image';
        $video = self::MEDIA_MODELS[$model] === 'video';
        $reference = $model === self::VIDEO_REFERENCE;
        $speech = $model === self::AUDIO_SPEECH;
        $music = $model === self::AUDIO_MUSIC;
        $avatar = $model === self::AVATAR;

        return [
            'image_path' => $image ? $model : self::IMAGE_SCHNELL,
            'video_path' => $video || $avatar ? $model : self::VIDEO,
            'video_status_path' => $avatar ? self::AVATAR_REQUEST_PATH : self::VIDEO_REQUEST_PATH,
            'sizes' => $image ? ['1024x1024', '1024x1792', '1792x1024'] : [],
            'durations' => $avatar ? range(5, 15) : ($video ? [2, 3, 5, 10] : []),
            'aspect_ratios' => $video && ! $reference ? ['16:9', '9:16', '1:1'] : [],
            'max_quantity' => $speech || $music || $avatar ? 1 : 4,
            'supports_size' => $image,
            'supports_n' => $image && $model !== self::IMAGE_PRO,
            'supports_duration' => $video || $avatar,
            'supports_aspect_ratio' => $video && ! $reference,
            'supports_pro' => $video,
            'supports_reference_image' => $video || $avatar,
            'reference_required' => $reference || $avatar,
            'reference_model' => $video ? self::VIDEO_REFERENCE : null,
            'audio_path' => $speech || $music ? $model : null,
            'audio_status_path' => $speech ? self::AUDIO_SPEECH_REQUEST_PATH : ($music ? self::AUDIO_MUSIC_REQUEST_PATH : null),
            'audio_kind' => $speech ? 'speech' : ($music ? 'music' : null),
            'voices' => $speech ? array_map(static fn (string $id): array => [
                'id' => $id,
                'label' => ucfirst(substr($id, 3)),
                'language' => 'en-US',
            ], [
                'af_heart', 'af_alloy', 'af_aoede', 'af_bella', 'af_jessica', 'af_kore', 'af_nicole',
                'af_nova', 'af_river', 'af_sarah', 'af_sky', 'am_adam', 'am_echo', 'am_eric',
                'am_fenrir', 'am_liam', 'am_michael', 'am_onyx', 'am_puck', 'am_santa',
            ]) : [],
            'speed_min' => $speech ? 0.1 : null,
            'speed_max' => $speech ? 5 : null,
            'speed_default' => $speech ? 1 : null,
            'duration_min' => $avatar ? 5 : ($music ? 1 : null),
            'duration_max' => $avatar ? 15 : ($music ? 190 : null),
            'duration_default' => $avatar ? 5 : ($music ? 190 : null),
            'max_characters' => $speech || $music ? 4000 : null,
            ...($avatar ? [
                'prompt_required' => true,
                'price_unit' => 'second',
                'avatar_audio_mode' => 'soundtrack',
                'reference_image_max_bytes' => 15_728_640,
                'reference_audio_max_bytes' => 15_000_000,
                'reference_audio_min_seconds' => 2,
            ] : []),
        ];
    }

    public static function imageRequest(array $payload): array
    {
        $model = $payload['model'] ?? null;
        $config = is_string($model) ? self::mediaConfig($model) : null;
        if ($config === null || (self::MEDIA_MODELS[$model] ?? null) !== 'image') {
            throw new AiProxyException('This fal image model is not supported.', 422);
        }
        $request = [
            'prompt' => self::prompt($payload),
            'enable_safety_checker' => true,
            'output_format' => 'png',
        ];
        $size = $payload['size'] ?? ($config['sizes'][0] ?? '1024x1024');
        if (! is_string($size) || ! in_array($size, $config['sizes'], true)) {
            throw new AiProxyException('The selected image size is not supported by this fal model.', 422);
        }
        [$width, $height] = explode('x', $size);
        $request['image_size'] = ['width' => (int) $width, 'height' => (int) $height];
        $quantity = $payload['n'] ?? 1;
        $maxQuantity = $config['supports_n'] ? $config['max_quantity'] : 1;
        if (! is_int($quantity) || $quantity < 1 || $quantity > $maxQuantity) {
            throw new AiProxyException('The selected image quantity is not supported by this fal model.', 422);
        }
        if ($config['supports_n']) {
            $request['num_images'] = $quantity;
        }

        return $request;
    }

    public static function imageResponse(array $data): array
    {
        if (! is_array($data['images'] ?? null) || $data['images'] === []
            || (is_array($data['has_nsfw_concepts'] ?? null) && in_array(true, $data['has_nsfw_concepts'], true))) {
            throw new AiProxyException('The fal image provider did not return a usable image.', 502);
        }
        $images = [];
        foreach ($data['images'] as $image) {
            if (! is_array($image) || ! is_string($image['url'] ?? null) || ! GeneratedImageStore::validResultUrl($image['url'])) {
                throw new AiProxyException('The fal image provider returned an invalid image.', 502);
            }
            $images[] = ['url' => $image['url']];
        }

        return ['data' => $images];
    }

    public static function videoRequest(array $payload): array
    {
        $model = $payload['model'] ?? null;
        if ($model === self::AVATAR) {
            return self::avatarRequest($payload);
        }
        if (! in_array($model, [self::VIDEO, self::VIDEO_REFERENCE], true)) {
            throw new AiProxyException('This fal video model is not supported.', 422);
        }
        $duration = $payload['duration'] ?? 2;
        $config = self::mediaConfig($model);
        $pro = $payload['pro_mode'] ?? false;
        if (! is_int($duration) || ! in_array($duration, $config['durations'], true) || ! is_bool($pro)) {
            throw new AiProxyException('The selected video options are not supported by this fal model.', 422);
        }
        $request = [
            'prompt' => self::prompt($payload),
            'num_frames' => $duration * 15,
            'fps' => 15,
            'num_inference_steps' => $pro ? 16 : 12,
            'video_quality' => $pro ? 'maximum' : 'high',
            'enable_safety_checker' => true,
            'enable_prompt_expansion' => false,
            'video_output_type' => 'X264 (.mp4)',
        ];
        if ($model === self::VIDEO_REFERENCE) {
            $image = $payload['image_url'] ?? null;
            if (! is_string($image) || strlen($image) > 13_981_040
                || preg_match('#^data:image/(?:jpeg|png|webp);base64,#', $image, $prefix) !== 1
                || strlen($image) === strlen($prefix[0])
                || strspn($image, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=', strlen($prefix[0])) !== strlen($image) - strlen($prefix[0])) {
                throw new AiProxyException('A validated reference image is required by this fal model.', 422);
            }
            $request['image_url'] = $image;
        } else {
            if (isset($payload['image_url'])) {
                throw new AiProxyException('Reference images require the fal image-to-video model.', 422);
            }
            $aspect = $payload['aspect_ratio'] ?? '16:9';
            if (! in_array($aspect, $config['aspect_ratios'], true)) {
                throw new AiProxyException('The selected video aspect ratio is not supported by this fal model.', 422);
            }
            $request['aspect_ratio'] = $aspect;
        }

        return $request;
    }

    private static function avatarRequest(array $payload): array
    {
        $config = self::mediaConfig(self::AVATAR);
        $duration = array_key_exists('duration', $payload) ? $payload['duration'] : $config['duration_default'];
        if (! is_int($duration) || $duration < $config['duration_min'] || $duration > $config['duration_max']
            || array_diff_key($payload, ['model' => true, 'prompt' => true, 'duration' => true, 'image_url' => true, 'audio_url' => true]) !== []) {
            throw new AiProxyException('The selected avatar options are not supported by this fal model.', 422);
        }

        return [
            'prompt' => self::prompt($payload),
            'duration' => $duration,
            'resolution' => '480P',
            'prompt_expansion_mode' => 'disabled',
            'enable_safety_checker' => true,
            'sync_mode' => false,
            'image_url' => self::inlineReference($payload['image_url'] ?? null, 'image', $config['reference_image_max_bytes']),
            // Fal replaces the soundtrack; this does not promise lip-sync accuracy.
            'target_audio_url' => self::inlineReference($payload['audio_url'] ?? null, 'audio', $config['reference_audio_max_bytes']),
        ];
    }

    /** Owned assets are signature-checked before encoding; validate the envelope without copying or decoding it. */
    private static function inlineReference(mixed $value, string $mediaType, int $maxBytes): string
    {
        $pattern = $mediaType === 'image'
            ? '#\Adata:image/(?:jpeg|png|webp);base64,#'
            : '#\Adata:audio/(?:mpeg|wav|x-wav|mp4|webm);base64,#';
        if (! is_string($value) || preg_match($pattern, $value, $prefix) !== 1) {
            throw new AiProxyException("A validated inline {$mediaType} reference is required by this fal model.", 422);
        }
        $offset = strlen($prefix[0]);
        $length = strlen($value) - $offset;
        if ($length < 4 || $length % 4 !== 0 || $length > intdiv($maxBytes + 2, 3) * 4) {
            throw new AiProxyException("The inline {$mediaType} reference is invalid or exceeds the fal size limit.", 422);
        }
        $padding = str_ends_with($value, '==') ? 2 : (str_ends_with($value, '=') ? 1 : 0);
        $dataLength = $length - $padding;
        $decodedBytes = intdiv($length, 4) * 3 - $padding;
        if ($decodedBytes > $maxBytes
            || strspn($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', $offset, $dataLength) !== $dataLength
            || ($padding === 2 && ! str_contains('AQgw', $value[$offset + $dataLength - 1]))
            || ($padding === 1 && ! str_contains('AEIMQUYcgkosw048', $value[$offset + $dataLength - 1]))) {
            throw new AiProxyException("The inline {$mediaType} reference is invalid or exceeds the fal size limit.", 422);
        }

        return $value;
    }

    public static function audioRequest(array $payload): array
    {
        $model = $payload['model'] ?? null;
        if (! in_array($model, [self::AUDIO_SPEECH, self::AUDIO_MUSIC], true)) {
            throw new AiProxyException('This fal audio model is not supported.', 422);
        }
        $config = self::mediaConfig($model);
        $prompt = self::prompt($payload);
        if (mb_strlen($prompt) > $config['max_characters']) {
            throw new AiProxyException('The audio prompt exceeds this model limit.', 422);
        }
        if ($model === self::AUDIO_SPEECH) {
            $voice = $payload['voice'] ?? $config['voices'][0]['id'];
            $speed = $payload['speed'] ?? $config['speed_default'];
            if (! in_array($voice, array_column($config['voices'], 'id'), true)
                || (! is_int($speed) && ! is_float($speed)) || ! is_finite((float) $speed)
                || $speed < $config['speed_min'] || $speed > $config['speed_max']
                || isset($payload['duration'])) {
                throw new AiProxyException('The selected speech options are not supported by this fal model.', 422);
            }

            return ['prompt' => $prompt, 'voice' => $voice, 'speed' => $speed];
        }
        $duration = $payload['duration'] ?? $config['duration_default'];
        if (! is_int($duration) || $duration < $config['duration_min'] || $duration > $config['duration_max']
            || isset($payload['voice']) || isset($payload['speed'])) {
            throw new AiProxyException('The selected music options are not supported by this fal model.', 422);
        }

        return [
            'prompt' => $prompt, 'seconds_total' => $duration,
            'num_inference_steps' => 8, 'guidance_scale' => 1, 'sync_mode' => false,
        ];
    }

    public static function chatRequest(array $payload): array
    {
        $allowed = ['model', 'messages', 'stream', 'stream_options', 'temperature', 'max_tokens', 'max_completion_tokens'];
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new AiProxyException('The requested completion options are not supported by fal.', 422);
        }
        if (array_key_exists('max_tokens', $payload) && array_key_exists('max_completion_tokens', $payload)) {
            throw new AiProxyException('Specify either max_tokens or max_completion_tokens, not both.', 422);
        }
        if (! is_string($payload['model'] ?? null) || preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._/-]+$#D', $payload['model']) !== 1
            || ! is_array($payload['messages'] ?? null) || $payload['messages'] === []) {
            throw new AiProxyException('The fal completion request is invalid.', 422);
        }
        $system = [];
        $conversation = [];
        foreach ($payload['messages'] as $message) {
            if (! is_array($message) || array_diff(array_keys($message), ['role', 'content']) !== []
                || ! is_string($message['content'] ?? null)
                || ! in_array($message['role'] ?? null, ['system', 'developer', 'user', 'assistant'], true)) {
                throw new AiProxyException('Fal chat supports text messages without tools or attachments.', 422);
            }
            if (in_array($message['role'], ['system', 'developer'], true)) {
                $system[] = $message['content'];
            } else {
                $conversation[] = $message;
            }
        }
        if ($conversation === [] || $conversation[array_key_last($conversation)]['role'] !== 'user') {
            throw new AiProxyException('The fal completion request must end with a user message.', 422);
        }
        $request = [
            'model' => $payload['model'],
            'prompt' => count($conversation) === 1 ? $conversation[0]['content']
                : "Continue this conversation with only the next assistant response. Conversation history (JSON):\n".json_encode($conversation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'reasoning' => false,
            'enable_web_search' => false,
        ];
        if ($system !== []) {
            $request['system_prompt'] = implode("\n\n", $system);
        }
        $maximum = $payload['max_tokens'] ?? $payload['max_completion_tokens'] ?? 4096;
        if (! is_int($maximum) || $maximum < 1) {
            throw new AiProxyException('The fal output token limit is invalid.', 422);
        }
        $request['max_tokens'] = $maximum;
        if (isset($payload['temperature'])) {
            if (! is_numeric($payload['temperature']) || $payload['temperature'] < 0 || $payload['temperature'] > 2) {
                throw new AiProxyException('The fal temperature is invalid.', 422);
            }
            $request['temperature'] = (float) $payload['temperature'];
        }
        if (isset($payload['stream_options']) && (! is_array($payload['stream_options'])
            || array_diff(array_keys($payload['stream_options']), ['include_usage']) !== [])) {
            throw new AiProxyException('The requested stream options are not supported by fal.', 422);
        }

        return $request;
    }

    public static function chatResponse(array $data, string $model): array
    {
        if (! is_string($data['output'] ?? null) || $data['output'] === '' || ! empty($data['error'])
            || ($data['partial'] ?? false) !== false) {
            throw new AiProxyException('The fal chat provider returned an incomplete response.', 502);
        }
        $usage = $data['usage'] ?? null;
        if (! is_array($usage) || ! is_int($usage['prompt_tokens'] ?? null) || $usage['prompt_tokens'] < 0
            || ! is_int($usage['completion_tokens'] ?? null) || $usage['completion_tokens'] < 0) {
            throw new AiProxyException('The fal chat provider did not return verifiable token usage.', 502);
        }

        return [
            'id' => 'chatcmpl-'.Str::uuid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $data['output']], 'finish_reason' => 'stop']],
            'usage' => [
                'prompt_tokens' => $usage['prompt_tokens'],
                'completion_tokens' => $usage['completion_tokens'],
                'total_tokens' => $usage['prompt_tokens'] + $usage['completion_tokens'],
            ],
        ];
    }

    private static function prompt(array $payload): string
    {
        if (! is_string($payload['prompt'] ?? null) || trim($payload['prompt']) === '') {
            throw new AiProxyException('The generation prompt is invalid.', 422);
        }

        return $payload['prompt'];
    }
}
