<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        DevCommands::artisan('queue:work exports --queue=exports --tries=1 --timeout=1800 --sleep=1', 'exports');

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('username')).'|'.$request->ip()),
        ]);

        RateLimiter::for('exports', fn (Request $request) => [
            Limit::perMinute(3)->by((string) ($request->user()?->id ?? $request->ip())),
        ]);
        RateLimiter::for('document-previews', fn (Request $request) => [
            Limit::perMinute(12)->by((string) ($request->user()?->id ?? $request->ip())),
        ]);
    }
}
