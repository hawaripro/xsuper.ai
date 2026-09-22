<?php

namespace App\Jobs;

use App\Services\AudioGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Two bounded 180s downloads plus status checks and private persistence.
    public int $timeout = 480;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $audioJobId) {}

    public function handle(AudioGenerationService $audio): void
    {
        $audio->poll($this->audioJobId);
    }
}
