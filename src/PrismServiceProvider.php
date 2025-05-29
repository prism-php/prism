<?php

namespace Prism\Prism;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Listeners\TelemetryEventListener;

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

        $this->bootTelemetry();
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

        $this->registerTelemetry();
    }

    protected function registerTelemetry(): void
    {
        $this->app->singleton(
            TelemetryManager::class,
            fn (): TelemetryManager => new TelemetryManager($this->app)
        );

        $this->app->alias(TelemetryManager::class, 'telemetry-manager');

        $this->app->singleton(TelemetryDriver::class, fn (): TelemetryDriver => $this->app->make(TelemetryManager::class)->driver());

        $this->app->singleton(TelemetryEventListener::class, fn (): TelemetryEventListener => new TelemetryEventListener(
            $this->app->make(TelemetryDriver::class),
            config('prism.telemetry.enabled', false)
        ));
    }

    protected function bootTelemetry(): void
    {
        if (config('prism.telemetry.enabled', false)) {
            Event::listen([
                \Prism\Prism\Events\PrismRequestStarted::class,
                \Prism\Prism\Events\PrismRequestCompleted::class,
                \Prism\Prism\Events\HttpRequestStarted::class,
                \Prism\Prism\Events\HttpRequestCompleted::class,
                \Prism\Prism\Events\ToolCallStarted::class,
                \Prism\Prism\Events\ToolCallCompleted::class,
            ], TelemetryEventListener::class);
        }
    }
}
