<?php

namespace App\Providers;

use App\Models\AudioJob;
use App\Models\ImageJob;
use App\Models\MediaToolJob;
use App\Models\Notification;
use App\Models\VideoJob;
use App\Observers\MediaJobObserver;
use App\Observers\NotificationObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Notification::observe(NotificationObserver::class);
        ImageJob::observe(MediaJobObserver::class);
        VideoJob::observe(MediaJobObserver::class);
        AudioJob::observe(MediaJobObserver::class);
        MediaToolJob::observe(MediaJobObserver::class);
    }
}
