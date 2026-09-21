<?php

namespace Tests\Unit\Media;

use App\Media\AdapterSupport;
use App\Media\Contracts\MediaProviderAdapter;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\MediaState;
use App\Media\Enums\OutputKind;
use App\Media\Enums\SubmitOutcome;
use App\Media\MediaCapability;
use App\Media\StatusResult;
use App\Media\SubmitResult;
use App\Models\AiProviderProfile;
use Tests\TestCase;

class AdapterContractTest extends TestCase
{
    public function test_submit_result_tagged_outcomes(): void
    {
        $this->assertSame(SubmitOutcome::Accepted, SubmitResult::accepted('task_123')->outcome);
        $this->assertSame('task_123', SubmitResult::accepted('task_123')->taskId);
        $this->assertSame(SubmitOutcome::Immediate, SubmitResult::immediate(['u'])->outcome);
        $this->assertSame(['u'], SubmitResult::immediate(['u'])->resultUrls);
        $this->assertSame(SubmitOutcome::Rejected, SubmitResult::rejected('bad')->outcome);
        $this->assertSame(SubmitOutcome::Uncertain, SubmitResult::uncertain()->outcome);
        $this->assertNull(SubmitResult::uncertain()->taskId);
    }

    public function test_status_result_states(): void
    {
        $this->assertSame(MediaState::Processing, StatusResult::processing()->state);
        $this->assertSame(MediaState::Completed, StatusResult::completed(['a', 'b'])->state);
        $this->assertSame(['a', 'b'], StatusResult::completed(['a', 'b'])->resultUrls);
        $this->assertSame(MediaState::Failed, StatusResult::failed('nope')->state);
    }

    public function test_fake_adapter_satisfies_interface(): void
    {
        $adapter = new class implements MediaProviderAdapter
        {
            public function support(): AdapterSupport
            {
                return new AdapterSupport(async: true, polling: true, webhook: false, cancel: false);
            }

            public function buildRequest(MediaCapability $capability, array $inputs, string $upstreamModel): array
            {
                return ['model' => $upstreamModel, ...$inputs];
            }

            public function submit(AiProviderProfile $provider, array $request): SubmitResult
            {
                return SubmitResult::accepted('t1');
            }

            public function pollStatus(AiProviderProfile $provider, string $taskId): StatusResult
            {
                return StatusResult::completed(['https://static.example/x.png']);
            }

            public function normalizeError(\Throwable $error): array
            {
                return ['public_code' => 'provider_error', 'public_message' => 'Gagal.', 'recoverable' => false, 'internal' => $error->getMessage()];
            }
        };

        $this->assertInstanceOf(MediaProviderAdapter::class, $adapter);
        $this->assertTrue($adapter->support()->async);
        $cap = new MediaCapability('m/x', MediaOperation::TextToImage, OutputKind::Image, 1);
        $this->assertSame('upstream-x', $adapter->buildRequest($cap, ['prompt' => 'hi'], 'upstream-x')['model']);
        $this->assertSame('t1', $adapter->submit(new AiProviderProfile, [])->taskId);
        $this->assertSame(MediaState::Completed, $adapter->pollStatus(new AiProviderProfile, 't1')->state);
        $this->assertFalse($adapter->normalizeError(new \RuntimeException('secret'))['recoverable']);
    }
}
