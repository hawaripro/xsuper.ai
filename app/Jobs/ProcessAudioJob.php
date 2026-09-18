<?php

namespace App\Jobs;

use App\Services\AudioGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 450;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $audioJobId) {}

    public function handle(AudioGenerationService $audio): void
    {
        $audio->process($this->audioJobId);
    }

    public function failed(?Throwable $exception): void
    {
        app(AudioGenerationService::class)->failSubmission($this->audioJobId);
    }
}
