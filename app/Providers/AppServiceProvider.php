<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

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
        // Allow access to Horizon (gate is overwritten by the package when it boots).
        Gate::define('viewHorizon', function ($user = null) {
            return true;
        });

        // Override Horizon auth after all providers boot so it always allows access (avoids 403
        // when logged into Filament, since the package redefines the gate and uses it in auth).
        $this->app->booted(function (): void {
            Horizon::auth(function () {
                return true;
            });
        });
    }
}
