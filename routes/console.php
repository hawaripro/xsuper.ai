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
    $this->info("Reconciled {$count} stale image jobs.");
})->purpose('Release wallet reservations for interrupted image requests');

Schedule::command('images:reconcile-stale')->everyMinute()->withoutOverlapping();

Artisan::command('videos:reconcile', function (VideoGenerationService $videos) {
    $this->info('Reconciled '.$videos->reconcile().' pending video jobs.');
})->purpose('Resume due video status polling without resubmitting generation');

Schedule::command('videos:reconcile')->everyMinute()->withoutOverlapping();

Artisan::command('audio:reconcile', function (AudioGenerationService $audio) {
    $this->info('Reconciled audio jobs: '.json_encode($audio->reconcile(), JSON_THROW_ON_ERROR));
})->purpose('Resume audio polling and release abandoned reservations without paid resubmission');

Schedule::command('audio:reconcile')->everyMinute()->withoutOverlapping();

Artisan::command('media-tools:reconcile', function (MediaToolService $tools) {
    $this->info('Reconciled media tools: '.json_encode($tools->reconcile(), JSON_THROW_ON_ERROR));
})->purpose('Recover unstarted media tools and retire abandoned process leases');

Schedule::command('media-tools:reconcile')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
