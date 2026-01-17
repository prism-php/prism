<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Drivers\LogDriver;
use Prism\Prism\Telemetry\Drivers\NullDriver;
use Prism\Prism\Telemetry\Drivers\OtlpDriver;
use RuntimeException;

class TelemetryManager
{
    public function __construct(
        protected Application $app
    ) {}

    /**
     * Resolve a telemetry driver by name.
     *
     * The driver config should contain a 'driver' key specifying the actual driver type.
     * This allows multiple named configs using the same underlying driver.
     *
     * @param  array<string, mixed>  $driverConfig  Optional config overrides
     *
     * @throws InvalidArgumentException
     */
    public function resolve(string $name, array $driverConfig = []): TelemetryDriver
    {
        $config = array_merge($this->getConfig($name), $driverConfig);

        // Get the actual driver type from config (e.g., 'otlp', 'log', 'null')
        $driverType = $config['driver'] ?? $name;

        $factory = sprintf('create%sDriver', ucfirst($driverType));

        if (method_exists($this, $factory)) {
            return $this->{$factory}($name, $config);
        }

        throw new InvalidArgumentException("Telemetry driver [{$driverType}] is not supported.");
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createNullDriver(string $name, array $config): NullDriver
    {
        return new NullDriver;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createLogDriver(string $name, array $config): LogDriver
    {
        return new LogDriver(
            channel: $config['channel'] ?? 'default'
        );
    }

    /**
     * Generic OTLP driver - works for any OTLP-compatible backend.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws RuntimeException
     */
    protected function createOtlpDriver(string $name, array $config): OtlpDriver
    {
        if (! class_exists(SpanExporter::class) || ! class_exists(TracerProvider::class)) {
            throw new RuntimeException(
                'OpenTelemetry SDK required for OTLP telemetry. '.
                'Run: composer require open-telemetry/sdk open-telemetry/exporter-otlp'
            );
        }

        return new OtlpDriver(driver: $name);
    }

    /**
     * Custom driver - allows external packages/apps to provide their own driver.
     *
     * Config should include a 'via' key with a class that implements __invoke($app, $config).
     *
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException
     */
    protected function createCustomDriver(string $name, array $config): TelemetryDriver
    {
        if (! isset($config['via'])) {
            throw new InvalidArgumentException(
                "Custom telemetry driver [{$name}] requires a 'via' configuration option."
            );
        }

        $factory = $config['via'];

        if (is_string($factory)) {
            $factory = $this->app->make($factory);
        }

        return $factory($this->app, $config);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getConfig(string $name): array
    {
        return config("prism.telemetry.drivers.{$name}", []);
    }
}
