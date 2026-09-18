<?php

namespace App\Jobs;

use App\Services\MediaToolService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessMediaToolJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 450;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $mediaToolJobId) {}

    public function handle(MediaToolService $tools): void
    {
        $tools->process($this->mediaToolJobId);
    }

    public function failed(?Throwable $exception): void
    {
        // A timed-out worker may still have an owned supervisor. Reconciliation waits
        // for its expired heartbeat watchdog before removing any working files.
        app(MediaToolService::class)->interrupted($this->mediaToolJobId);
    }
}
