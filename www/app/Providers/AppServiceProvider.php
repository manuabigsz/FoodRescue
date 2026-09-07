<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
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
        // This API uses Bearer tokens. Browser session authentication will be configured with the frontend.
        config(['sanctum.guard' => []]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by('api:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(10)
            ->by('register:'.$request->ip()));
        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $key = is_string($email) ? hash('sha256', mb_strtolower(trim($email))) : 'invalid';

            return [
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login-account:'.$key.'|'.$request->ip()),
            ];
        });
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(5)
            ->by('sensitive:'.$request->user()->id));
    }
}
