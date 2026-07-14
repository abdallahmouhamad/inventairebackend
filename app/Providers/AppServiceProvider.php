<?php

namespace App\Providers;

use App\Services\X3\ReferentielX3Connecteur;
use App\Services\X3\X3ConnecteurInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(X3ConnecteurInterface::class, ReferentielX3Connecteur::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
