<?php

namespace App\Jobs;

use App\Services\ImageGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 450;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $imageJobId) {}

    public function handle(ImageGenerationService $images): void
    {
        $images->process($this->imageJobId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ImageGenerationService::class)->failSubmission($this->imageJobId);
    }
}
