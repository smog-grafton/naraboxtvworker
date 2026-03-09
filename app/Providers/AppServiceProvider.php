<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
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
        // Allow access to Horizon. Rely on Coolify/proxy (e.g. HTTP auth or private URL) for access control.
        Gate::define('viewHorizon', function ($user = null) {
            return true;
        });
    }
}
