<?php

namespace App\Jobs;

use App\Services\ThreeDGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessThreeDJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 450;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $threeDJobId) {}

    public function handle(ThreeDGenerationService $models): void
    {
        $models->process($this->threeDJobId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ThreeDGenerationService::class)->failSubmission($this->threeDJobId);
    }
}
