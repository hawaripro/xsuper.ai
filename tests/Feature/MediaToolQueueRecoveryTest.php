<?php

namespace Tests\Feature;

use App\Models\MediaToolJob;
use App\Models\User;
use App\Services\MediaToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A media job whose private workspace cannot be created (e.g. the storage path
 * is not writable by the worker) must be finalised as failed. Previously the
 * failure paths all depended on acquiring the file lock — which is exactly what
 * fails when the directory cannot be created — so such jobs sat at "queued"
 * (the UI's "Menunggu antrean") forever.
 */
class MediaToolQueueRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $blockedJobId = '';

    protected function tearDown(): void
    {
        if ($this->blockedJobId !== '') {
            Storage::disk('local')->delete('media-tools/'.$this->blockedJobId);
        }
        parent::tearDown();
    }

    private function pendingJob(User $user): MediaToolJob
    {
        $job = MediaToolJob::create([
            'user_id' => $user->id, 'job_id' => (string) Str::uuid(), 'kind' => 'download',
            'format' => 'mp4', 'source_url' => 'https://example.com/video', 'input_name' => null,
            'title' => 'Download', 'status' => 'pending', 'stage' => 'queued', 'dispatched_at' => now(),
        ]);

        // Placing a plain file where the per-job directory belongs makes mkdir()
        // fail deterministically on every platform, standing in for the server's
        // permission-denied condition.
        $this->blockedJobId = $job->job_id;
        Storage::disk('local')->put('media-tools/'.$job->job_id, 'x');

        return $job;
    }

    public function test_processing_fails_a_job_whose_workspace_cannot_be_created(): void
    {
        $tools = app(MediaToolService::class);
        $job = $this->pendingJob(User::factory()->create());

        $tools->process($job->id);

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('failed', $job->fresh()->stage);
    }

    public function test_interrupted_finalises_a_pending_job_without_a_lock(): void
    {
        $tools = app(MediaToolService::class);
        $job = $this->pendingJob(User::factory()->create());

        // Mirrors ProcessMediaToolJob::failed(): the job never began its external
        // process, so it must be marked failed even though the lock is unavailable.
        $tools->interrupted($job->id);

        $this->assertSame('failed', $job->fresh()->status);
    }

    public function test_reconcile_fails_a_stale_pending_job_without_a_lock(): void
    {
        $tools = app(MediaToolService::class);
        $job = $this->pendingJob(User::factory()->create());
        $job->forceFill(['created_at' => now()->subMinutes(20)])->saveQuietly();

        $counts = $tools->reconcile();

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame(1, $counts['failed']);
    }
}
