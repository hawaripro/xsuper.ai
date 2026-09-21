<?php

namespace Tests\Unit\Media;

use App\Media\CapabilityInput;
use App\Media\CapabilityParam;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\OutputKind;
use App\Media\MediaCapability;
use Tests\TestCase;

class MediaCapabilityTest extends TestCase
{
    private function sample(): MediaCapability
    {
        return new MediaCapability(
            'kinovi-ai/gpt-image-2',
            MediaOperation::TextToImage,
            OutputKind::Image,
            1,
            [new CapabilityInput('prompt', InputRole::Prompt, 'string', single: true, max: 1, required: true)],
            [new CapabilityParam('size', 'enum', default: '1024x1024', options: ['1024x1024', '1024x1792'])],
        );
    }

    public function test_round_trips_through_array(): void
    {
        $cap = $this->sample();
        $this->assertSame($cap->toArray(), MediaCapability::fromArray($cap->toArray())->toArray());
    }

    public function test_exposes_required_inputs_and_lookup_by_role(): void
    {
        $cap = $this->sample();
        $this->assertCount(1, $cap->requiredInputs());
        $this->assertTrue($cap->input(InputRole::Prompt)->required);
        $this->assertNull($cap->input(InputRole::ImageRef));
        $this->assertSame('size', $cap->param('size')->name);
    }

    public function test_from_array_rejects_unknown_operation(): void
    {
        $this->expectException(\ValueError::class);
        MediaCapability::fromArray([
            'model_public_id' => 'x', 'operation' => 'no_such_op', 'output_kind' => 'image',
            'contract_version' => 1, 'inputs' => [], 'params' => [],
        ]);
    }

    public function test_input_key_is_distinct_from_role(): void
    {
        $input = new CapabilityInput('start_image', InputRole::InitFrame, 'asset', single: true, max: 1, required: false);
        $this->assertSame('start_image', $input->key);
        $this->assertSame(InputRole::InitFrame, $input->role);
    }
}
