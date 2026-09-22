<?php

namespace App\Jobs;

use App\Services\ThreeDGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollThreeDJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $threeDJobId) {}

    public function handle(ThreeDGenerationService $models): void
    {
        $models->poll($this->threeDJobId);
    }
}
