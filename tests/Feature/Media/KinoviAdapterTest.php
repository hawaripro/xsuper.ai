<?php

namespace Tests\Feature\Media;

use App\Media\Adapters\KinoviAdapter;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\MediaState;
use App\Media\Enums\OutputKind;
use App\Media\Enums\SubmitOutcome;
use App\Media\MediaCapability;
use App\Models\AiProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KinoviAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): AiProviderProfile
    {
        return AiProviderProfile::create([
            'slug' => 'kinovi-ai', 'name' => 'Kinovi', 'protocol' => 'kinovi',
            'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'k', 'is_enabled' => true,
        ]);
    }

    public function test_build_request_maps_validated_inputs_to_kinovi_payload(): void
    {
        $cap = new MediaCapability('kinovi-ai/gpt-image-2', MediaOperation::TextToImage, OutputKind::Image, 1);
        $request = app(KinoviAdapter::class)->buildRequest(
            $cap, ['inputs' => ['prompt' => 'a red apple'], 'params' => ['size' => '1024x1024']], 'gpt-image-2'
        );
        $this->assertSame(['model' => 'gpt-image-2', 'prompt' => 'a red apple', 'size' => '1024x1024'], $request);
    }

    public function test_submit_then_poll_over_faked_kinovi(): void
    {
        Http::fake([
            'https://kinovi.ai/api/v1/jobs/createTask' => Http::response(['taskId' => 'task_abc123']),
            'https://kinovi.ai/api/v1/jobs/recordInfo*' => Http::response(['status' => 'success', 'output' => [['url' => 'https://static.seedance2-pro.com/x.png']]]),
        ]);
        $adapter = app(KinoviAdapter::class);
        $provider = $this->provider();

        $submit = $adapter->submit($provider, ['model' => 'gpt-image-2', 'prompt' => 'apple', 'size' => '1024x1024']);
        $this->assertSame(SubmitOutcome::Accepted, $submit->outcome);
        $this->assertSame('task_abc123', $submit->taskId);

        $status = $adapter->pollStatus($provider, 'task_abc123');
        $this->assertSame(MediaState::Completed, $status->state);
        $this->assertSame(['https://static.seedance2-pro.com/x.png'], $status->resultUrls);
    }

    public function test_processing_status_is_reported_while_generating(): void
    {
        Http::fake(['https://kinovi.ai/api/v1/jobs/recordInfo*' => Http::response(['status' => 'generating', 'output' => null])]);
        $status = app(KinoviAdapter::class)->pollStatus($this->provider(), 'task_x');
        $this->assertSame(MediaState::Processing, $status->state);
    }

    public function test_invalid_input_is_a_definitive_rejection_not_uncertain(): void
    {
        // Empty prompt fails KinoviProtocol::imageTask (422) before any HTTP call.
        $submit = app(KinoviAdapter::class)->submit($this->provider(), ['model' => 'gpt-image-2', 'prompt' => '', 'size' => '1024x1024']);
        $this->assertSame(SubmitOutcome::Rejected, $submit->outcome);
    }
}
