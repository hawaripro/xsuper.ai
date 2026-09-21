<?php

namespace App\Jobs;

use App\Services\ImageGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $imageJobId) {}

    public function handle(ImageGenerationService $images): void
    {
        $images->poll($this->imageJobId);
    }
}
