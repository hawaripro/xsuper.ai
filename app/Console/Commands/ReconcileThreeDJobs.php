<?php

namespace App\Console\Commands;

use App\Services\ThreeDGenerationService;
use Illuminate\Console\Command;

class ReconcileThreeDJobs extends Command
{
    protected $signature = 'model3d:reconcile';

    protected $description = 'Resume persisted 3D requests and release abandoned reservations without paid resubmission';

    public function handle(ThreeDGenerationService $models): int
    {
        $this->info('Reconciled 3D jobs: '.json_encode($models->reconcile(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
