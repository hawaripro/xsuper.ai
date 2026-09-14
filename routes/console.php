<?php

use App\Services\ImageGenerationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Artisan::command('images:reconcile-stale {--minutes=3}', function (ImageGenerationService $images) {
    $minutes = max(1, (int) $this->option('minutes'));
    $count = $images->reconcileStaleReservations($minutes);
    $this->info("Reconciled {$count} stale image jobs.");
})->purpose('Release wallet reservations for interrupted image requests');

Schedule::command('images:reconcile-stale')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
