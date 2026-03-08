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
        // Allow any authenticated user to view Horizon (overrides empty whitelist from horizon:install).
        Gate::define('viewHorizon', function ($user) {
            return $user !== null;
        });
    }
}
