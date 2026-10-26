<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        RateLimiter::for('subtitle-api', function (Request $request) {
            $client = $request->attributes->get('api_client');
            $key = $client?->id ?? $request->ip();
            $limits = [Limit::perMinute((int) env('SUBTITLE_API_RATE_LIMIT', 60))->by('client:'.$key)];
            if ($request->isMethod('post')) {
                $limits[] = Limit::perHour((int) env('SUBTITLE_API_UPLOADS_PER_HOUR', 20))->by('uploads:'.$key);
            }

            return $limits;
        });
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
