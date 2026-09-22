<?php

namespace Tests\Feature\Media;

use App\Exceptions\AiProxyException;
use App\Media\CapabilityValidator;
use App\Media\Exceptions\CapabilityValidationException;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Services\FalProtocol;
use App\Services\MediaModelConfig;
use Tests\TestCase;

class FalAvatarContractTest extends TestCase
{
    public function test_photo_and_audio_map_to_the_fixed_soundtrack_request(): void
    {
        $payload = $this->payload();

        $this->assertSame([
            'prompt' => 'The presenter speaks calmly to the camera.',
            'duration' => 6,
            'resolution' => '480P',
            'prompt_expansion_mode' => 'disabled',
            'enable_safety_checker' => true,
            'sync_mode' => false,
            'image_url' => $payload['image_url'],
            'target_audio_url' => $payload['audio_url'],
        ], FalProtocol::videoRequest($payload));
    }

    public function test_duration_boundaries_are_integer_seconds_and_prompt_is_required(): void
    {
        foreach ([5, 15] as $duration) {
            $request = FalProtocol::videoRequest([...$this->payload(), 'duration' => $duration]);
            $this->assertSame($duration, $request['duration']);
        }
        foreach ([4, 16, 5.5, '5', null] as $duration) {
            $this->assertInvalidRequest([...$this->payload(), 'duration' => $duration]);
        }
        $payload = $this->payload();
        unset($payload['prompt']);
        $this->assertInvalidRequest($payload);
        $this->assertInvalidRequest([...$this->payload(), 'prompt' => '   ']);
    }

    public function test_missing_or_remote_references_cannot_fall_back_to_other_generation_modes(): void
    {
        foreach (['image_url', 'audio_url'] as $key) {
            $payload = $this->payload();
            unset($payload[$key]);
            $this->assertInvalidRequest($payload);
            $this->assertInvalidRequest([...$this->payload(), $key => 'https://example.com/reference']);
        }
    }

    public function test_references_reject_wrong_media_empty_data_and_malformed_base64(): void
    {
        $payload = $this->payload();
        $this->assertInvalidRequest([...$payload, 'image_url' => $payload['audio_url']]);
        $this->assertInvalidRequest([...$payload, 'audio_url' => $payload['image_url']]);
        $this->assertInvalidRequest([...$payload, 'image_url' => 'data:image/svg+xml;base64,PHN2Zy8+']);
        $this->assertInvalidRequest([...$payload, 'audio_url' => 'data:audio/aac;base64,YWJj']);

        foreach (['', 'Zg=', 'Z=g=', 'Zh==', "Zg==\n", '****'] as $encoded) {
            $this->assertInvalidRequest([...$payload, 'audio_url' => 'data:audio/wav;base64,'.$encoded]);
        }
    }

    public function test_audio_accepts_all_canonical_base64_padding_lengths(): void
    {
        foreach ([32_044, 32_045, 32_046] as $bytes) {
            $audio = 'data:audio/wav;base64,'.base64_encode($this->wave($bytes));
            $request = FalProtocol::videoRequest([...$this->payload(), 'audio_url' => $audio]);
            $this->assertSame($audio, $request['target_audio_url']);
        }
    }

    public function test_audio_limit_is_fifteen_million_decoded_bytes_not_fifteen_mib(): void
    {
        $payload = [...$this->payload(), 'audio_url' => 'data:audio/wav;base64,'.base64_encode($this->wave(15_000_000))];
        $this->assertSame($payload['audio_url'], FalProtocol::videoRequest($payload)['target_audio_url']);
        unset($payload);

        $this->assertInvalidRequest([
            ...$this->payload(),
            'audio_url' => 'data:audio/wav;base64,'.base64_encode($this->wave(15_000_001)),
        ]);
    }

    public function test_avatar_image_limit_matches_the_owned_asset_policy(): void
    {
        $png = base64_decode(substr($this->payload()['image_url'], strlen('data:image/png;base64,')), true);
        $payload = [...$this->payload(), 'image_url' => 'data:image/png;base64,'.base64_encode(str_pad($png, 15_728_640, "\0"))];
        $this->assertSame($payload['image_url'], FalProtocol::videoRequest($payload)['image_url']);
        unset($payload);

        $this->assertInvalidRequest([
            ...$this->payload(),
            'image_url' => 'data:image/png;base64,'.base64_encode(str_pad($png, 15_728_641, "\0")),
        ]);
    }

    public function test_unsupported_provider_controls_cannot_change_price_or_bypass_private_audio(): void
    {
        $this->assertInvalidRequest([...$this->payload(), 'resolution' => '1080P']);
        $this->assertInvalidRequest([...$this->payload(), 'target_audio_url' => 'https://example.com/audio.wav']);
    }

    public function test_native_capability_enforces_the_fal_prompt_duration_and_supported_options(): void
    {
        $model = $this->model('fal', FalProtocol::AVATAR);
        $capability = MediaModelConfig::deriveCapabilities($model)['talking_avatar'];
        $validator = new CapabilityValidator;
        $input = ['avatar_photo' => 'owned-photo', 'speech_audio' => 'owned-audio', 'prompt' => 'Speak calmly.', 'duration' => 6];
        $validated = $validator->validate($capability, $input);
        $this->assertSame(['duration' => 6], $validated['params']);

        foreach (['prompt' => '', 'duration' => 4, 'aspect_ratio' => '16:9', 'pro' => true, 'count' => 2] as $key => $value) {
            try {
                $validator->validate($capability, [...$input, $key => $value]);
                $this->fail('The native avatar capability accepted unsupported '.$key.'.');
            } catch (CapabilityValidationException $exception) {
                $this->assertArrayHasKey($key, $exception->errors());
            }
        }
    }

    public function test_kinovi_keeps_optional_prompt_two_second_minimum_and_aspect_selection(): void
    {
        $model = $this->model('kinovi', 'minimax-h3-turbo-avatar-talking');
        $capability = MediaModelConfig::deriveCapabilities($model)['talking_avatar'];
        $validated = (new CapabilityValidator)->validate($capability, [
            'avatar_photo' => 'owned-photo', 'speech_audio' => 'owned-audio', 'duration' => 2, 'aspect_ratio' => '9:16',
        ]);

        $this->assertSame(['duration' => 2, 'aspect_ratio' => '9:16'], $validated['params']);
        $this->assertArrayNotHasKey('prompt', $validated['inputs']);
    }

    private function model(string $protocol, string $upstream): AiModelProfile
    {
        $model = new AiModelProfile(['model_id' => 'avatar-fixture', 'upstream_model_id' => $upstream, 'category' => 'avatar']);
        $model->setRelation('provider', new AiProviderProfile(['protocol' => $protocol]));

        return $model;
    }

    private function payload(): array
    {
        return [
            'model' => FalProtocol::AVATAR,
            'prompt' => 'The presenter speaks calmly to the camera.',
            'duration' => 6,
            'image_url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=',
            'audio_url' => 'data:audio/wav;base64,'.base64_encode($this->wave(32_044)),
        ];
    }

    private function wave(int $bytes): string
    {
        return 'RIFF'.pack('V', $bytes - 8).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            .'data'.pack('V', $bytes - 44).str_repeat("\0", $bytes - 44);
    }

    private function assertInvalidRequest(array $payload): void
    {
        try {
            FalProtocol::videoRequest($payload);
            $this->fail('An invalid avatar request reached provider payload construction.');
        } catch (AiProxyException $exception) {
            $this->assertSame(422, $exception->responseStatus());
        }
    }
}
