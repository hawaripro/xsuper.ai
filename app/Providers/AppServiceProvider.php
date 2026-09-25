<?php

namespace App\Providers;

use App\Models\AudioJob;
use App\Models\ImageJob;
use App\Models\MediaToolJob;
use App\Models\Notification;
use App\Models\VideoJob;
use App\Observers\MediaJobObserver;
use App\Observers\NotificationObserver;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Event;
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
        RateLimiter::for('media-api-create', fn (Request $request) => Limit::perMinute(20)
            ->by('media-api:'.$request->attributes->get('api_key')->id)
            ->response(fn (Request $request, array $headers) => \App\Http\Api\ApiErrorResponse::openAi(
                'Media creation is limited to 20 requests per minute per key.', 'rate_limit_error', 'rate_limit_exceeded', 429)->withHeaders($headers)));

        Notification::observe(NotificationObserver::class);
        ImageJob::observe(MediaJobObserver::class);
        VideoJob::observe(MediaJobObserver::class);
        AudioJob::observe(MediaJobObserver::class);
        MediaToolJob::observe(MediaJobObserver::class);

        // Prevent session fixation: regenerate the session id on every session
        // login (password, Google OAuth, and Fortify registration alike).
        Event::listen(Login::class, function (Login $event): void {
            if ($event->guard === 'web' && request()->hasSession()) {
                request()->session()->regenerate();
            }
        });
    }
}
