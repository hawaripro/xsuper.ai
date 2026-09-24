<?php

use App\Services\AudioGenerationService;
use App\Services\ImageGenerationService;
use App\Services\MediaToolService;
use App\Services\VideoGenerationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('images:reconcile-stale {--minutes=3}', function (ImageGenerationService $images) {
    $minutes = max(1, (int) $this->option('minutes'));
    $count = $images->reconcileStaleReservations($minutes);
    // Recover coordinator jobs whose ProcessImageJob dispatch was lost (queue down at submit):
    // re-queue the idempotent processor; it only acts on a still-queued job (no double charge).
    $redispatched = $images->redispatchStalePending($minutes);
    $this->info("Reconciled {$count} stale image jobs; re-dispatched {$redispatched} undispatched jobs.");
})->purpose('Release interrupted image reservations and recover lost dispatches');

Schedule::command('images:reconcile-stale')->everyMinute()->withoutOverlapping();

Artisan::command('videos:reconcile', function (VideoGenerationService $videos) {
    $this->info('Reconciled '.$videos->reconcile().' pending video jobs.');
})->purpose('Resume due video status polling without resubmitting generation');

Schedule::command('videos:reconcile')->everyMinute()->withoutOverlapping();

Artisan::command('audio:reconcile', function (AudioGenerationService $audio) {
    $this->info('Reconciled audio jobs: '.json_encode($audio->reconcile(), JSON_THROW_ON_ERROR));
})->purpose('Resume audio polling and release abandoned reservations without paid resubmission');

Schedule::command('audio:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('model3d:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('media:reconcile-workspace')->everyMinute()->withoutOverlapping();
Schedule::command('media:reconcile-realtime')->everyMinute()->withoutOverlapping();

Artisan::command('media-tools:reconcile', function (MediaToolService $tools) {
    $this->info('Reconciled media tools: '.json_encode($tools->reconcile(), JSON_THROW_ON_ERROR));
})->purpose('Recover unstarted media tools and retire abandoned process leases');

Schedule::command('media-tools:reconcile')->everyMinute()->withoutOverlapping();

Schedule::command('library:purge')->weekly()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
