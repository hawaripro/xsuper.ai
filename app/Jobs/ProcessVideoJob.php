<?php

namespace App\Jobs;

use App\Services\VideoGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 450;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $videoJobId) {}

    public function handle(VideoGenerationService $videos): void
    {
        $videos->process($this->videoJobId);
    }

    public function failed(?Throwable $exception): void
    {
        app(VideoGenerationService::class)->failSubmission($this->videoJobId, 'The video request was interrupted. It was not automatically resubmitted.');
    }
}
