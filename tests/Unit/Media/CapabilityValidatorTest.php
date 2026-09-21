<?php

namespace Tests\Unit\Media;

use App\Media\CapabilityInput;
use App\Media\CapabilityParam;
use App\Media\CapabilityValidator;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\OutputKind;
use App\Media\Exceptions\CapabilityValidationException;
use App\Media\MediaCapability;
use Tests\TestCase;

class CapabilityValidatorTest extends TestCase
{
    private CapabilityValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new CapabilityValidator;
    }

    private function textToImage(): MediaCapability
    {
        return new MediaCapability('kinovi-ai/gpt-image-2', MediaOperation::TextToImage, OutputKind::Image, 1,
            [new CapabilityInput('prompt', InputRole::Prompt, 'string', single: true, max: 1, required: true)],
            [new CapabilityParam('size', 'enum', default: '1024x1024', options: ['1024x1024', '1024x1792', '1792x1024'])],
        );
    }

    private function imageToVideo(): MediaCapability
    {
        // init_frame is required only when mode = reference
        return new MediaCapability('vendor/i2v', MediaOperation::ImageToVideo, OutputKind::Video, 1,
            [
                new CapabilityInput('prompt', InputRole::Prompt, 'string', single: true, max: 1, required: true),
                new CapabilityInput('image_ref', InputRole::InitFrame, 'asset', single: false, max: 2, required: false,
                    requiredWhen: ['param_equals' => ['name' => 'mode', 'value' => 'reference']]),
            ],
            [new CapabilityParam('mode', 'enum', default: 'text', options: ['text', 'reference'])],
        );
    }

    public function test_valid_text_to_image_returns_sanitized_inputs_and_params(): void
    {
        $out = $this->validator->validate($this->textToImage(), ['prompt' => '  a red apple ', 'size' => '1024x1024']);
        $this->assertSame(['prompt' => 'a red apple'], $out['inputs']);
        $this->assertSame(['size' => '1024x1024'], $out['params']);
    }

    public function test_missing_required_prompt_throws(): void
    {
        try {
            $this->validator->validate($this->textToImage(), ['size' => '1024x1024']);
            $this->fail('expected CapabilityValidationException');
        } catch (CapabilityValidationException $e) {
            $this->assertArrayHasKey('prompt', $e->errors());
        }
    }

    public function test_unknown_field_is_rejected(): void
    {
        try {
            $this->validator->validate($this->textToImage(), ['prompt' => 'x', 'foo' => 'bar']);
            $this->fail('expected CapabilityValidationException');
        } catch (CapabilityValidationException $e) {
            $this->assertArrayHasKey('foo', $e->errors());
        }
    }

    public function test_param_enum_out_of_range_throws(): void
    {
        try {
            $this->validator->validate($this->textToImage(), ['prompt' => 'x', 'size' => '9x9']);
            $this->fail('expected CapabilityValidationException');
        } catch (CapabilityValidationException $e) {
            $this->assertArrayHasKey('size', $e->errors());
        }
    }

    public function test_param_default_applied_when_omitted(): void
    {
        $out = $this->validator->validate($this->textToImage(), ['prompt' => 'x']);
        $this->assertSame('1024x1024', $out['params']['size']);
    }

    public function test_conditional_required_input_enforced(): void
    {
        // mode=reference makes image_ref required
        try {
            $this->validator->validate($this->imageToVideo(), ['prompt' => 'x', 'mode' => 'reference']);
            $this->fail('expected CapabilityValidationException');
        } catch (CapabilityValidationException $e) {
            $this->assertArrayHasKey('image_ref', $e->errors());
        }
        // providing it satisfies the rule
        $out = $this->validator->validate($this->imageToVideo(), ['prompt' => 'x', 'mode' => 'reference', 'image_ref' => ['asset-1']]);
        $this->assertSame(['asset-1'], $out['inputs']['image_ref']);
        // mode=text (default) does not require it
        $ok = $this->validator->validate($this->imageToVideo(), ['prompt' => 'x']);
        $this->assertArrayNotHasKey('image_ref', $ok['inputs']);
        $this->assertSame('text', $ok['params']['mode']);
    }

    public function test_multi_asset_cardinality_max_enforced(): void
    {
        try {
            $this->validator->validate($this->imageToVideo(), ['prompt' => 'x', 'mode' => 'reference', 'image_ref' => ['a', 'b', 'c']]);
            $this->fail('expected CapabilityValidationException');
        } catch (CapabilityValidationException $e) {
            $this->assertArrayHasKey('image_ref', $e->errors());
        }
    }
}
