<?php

namespace EFacturiPlus;

use Illuminate\Support\ServiceProvider;

class EFacturiPlusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/efacturiplus.php', 'efacturiplus');
        $this->app->singleton(Client::class, fn ($app) => new Client((string) $app['config']['efacturiplus.key'], (string) $app['config']['efacturiplus.url']));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/efacturiplus.php' => config_path('efacturiplus.php')], 'efacturiplus-config');
    }
}
