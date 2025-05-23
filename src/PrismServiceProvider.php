<?php

namespace Prism\Prism;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\Prism\Contracts\Telemetry;
use Prism\Prism\Telemetry\NullDriver;
use Prism\Prism\Telemetry\OpenTelemetryDriver;

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

        $this->registerTelemetry();
        $this->registerTelemetryService();
    }

    protected function registerTelemetry(): void
    {
        $this->app->singleton(TracerInterface::class, function (): \OpenTelemetry\API\Trace\TracerInterface {
            if (! config('prism.telemetry.enabled', false)) {
                return Globals::tracerProvider()->getTracer('prism');
            }

            $resource = ResourceInfoFactory::emptyResource()->merge(
                ResourceInfo::create(
                    Attributes::create([
                        'service.name' => config('prism.telemetry.service_name', 'prism'),
                        'service.version' => config('prism.telemetry.service_version', '1.0.0'),
                    ])
                )
            );

            $spanExporter = new SpanExporter(
                (new OtlpHttpTransportFactory)->create(
                    config('prism.telemetry.endpoint', 'http://localhost:4318/v1/traces'),
                    'application/x-protobuf'
                )
            );

            $tracerProvider = TracerProvider::builder()
                ->addSpanProcessor(new SimpleSpanProcessor($spanExporter))
                ->setResource($resource)
                ->build();

            return $tracerProvider->getTracer('prism');
        });
    }

    protected function registerTelemetryService(): void
    {
        $this->app->singleton(Telemetry::class, function (): Telemetry {
            $enabled = config('prism.telemetry.enabled', false);

            if (! $enabled) {
                return new NullDriver;
            }

            return new OpenTelemetryDriver(
                tracer: $this->app->make(TracerInterface::class),
                enabled: $enabled
            );
        });
    }
}
