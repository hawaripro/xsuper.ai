<?php

namespace Tests\Feature\Media;

use App\Jobs\ProcessImageJob;
use App\Models\ImageJob;
use App\Models\User;
use App\Services\ImageGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Q07: a coordinator job persists in one transaction and dispatches its processor
 * afterCommit. If that dispatch is lost (queue briefly unavailable), the committed
 * pending job must be recoverable without a duplicate charge. redispatchStalePending
 * re-queues the idempotent processor; process() only acts on a still-queued job.
 */
class DispatchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function pendingJob(User $user, int $ageMinutes): ImageJob
    {
        $job = ImageJob::create([
            'user_id' => $user->id, 'job_id' => (string) Str::uuid(), 'model' => 'kinovi-ai/gpt-image-2',
            'prompt' => 'x', 'size' => '1024x1024', 'quantity' => 1, 'status' => 'pending', 'stage' => 'queued',
            'billing_reference_id' => 'image:'.Str::uuid(), 'billing_status' => 'reserved', 'billing_mode' => 'tokens', 'tokens_reserved' => 10,
        ]);
        $job->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

        return $job;
    }

    public function test_redispatches_only_stale_undispatched_pending_jobs(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $stale = $this->pendingJob($user, 10);
        $fresh = $this->pendingJob($user, 0);

        $count = app(ImageGenerationService::class)->redispatchStalePending(5);

        $this->assertSame(1, $count, 'only the stale pending job is re-dispatched');
        Queue::assertPushed(ProcessImageJob::class, fn (ProcessImageJob $job): bool => $job->imageJobId === $stale->id);
        Queue::assertNotPushed(ProcessImageJob::class, fn (ProcessImageJob $job): bool => $job->imageJobId === $fresh->id);
    }

    public function test_scheduled_reconcile_command_recovers_lost_dispatch(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $stale = $this->pendingJob($user, 10);

        $this->artisan('images:reconcile-stale', ['--minutes' => 5])->assertSuccessful();

        Queue::assertPushed(ProcessImageJob::class, fn (ProcessImageJob $job): bool => $job->imageJobId === $stale->id);
    }
}
