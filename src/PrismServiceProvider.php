<?php

namespace Prism\Prism;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Prism\Prism\Contracts\Telemetry;
use Prism\Prism\Telemetry\LogDriver;

class PrismServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/prism.php' => config_path('prism.php'),
        ], 'prism-config');

        if (config('prism.prism_server.enabled')) {
            Route::group([
                'middleware' => config('prism.prism_server.middleware', []),
            ], function (): void {
                $this->loadRoutesFrom(__DIR__.'/Routes/PrismServer.php');
            });
        }
    }

    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/prism.php',
            'prism'
        );

        $this->app->singleton(
            PrismManager::class,
            fn (): PrismManager => new PrismManager($this->app)
        );

        $this->app->alias(PrismManager::class, 'prism-manager');

        $this->app->singleton(
            'prism-server',
            fn (): PrismServer => new PrismServer
        );

        $this->registerTelemetryDrivers();
        $this->registerTelemetryService();
    }

    protected function registerTelemetryDrivers(): void
    {
        $this->app->bind(LogDriver::class, fn (): LogDriver => new LogDriver(
            channel: config('prism.telemetry.log_channel', 'default'),
            enabled: config('prism.telemetry.enabled', false)
        ));
    }

    protected function registerTelemetryService(): void
    {
        $this->app->singleton(Telemetry::class, fn (): Telemetry => $this->app->make(LogDriver::class));
    }
}
