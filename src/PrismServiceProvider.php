<?php

namespace Prism\Prism;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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

        $this->app->singleton(PrismManager::class, function (): PrismManager {
            if (method_exists(RequestException::class, 'dontTruncate')) {
                RequestException::dontTruncate();
            }

            return new PrismManager($this->app);
        });

        $this->app->alias(PrismManager::class, 'prism-manager');

        $this->app->singleton(
            'prism-server',
            fn (): PrismServer => new PrismServer
        );
    }
}
