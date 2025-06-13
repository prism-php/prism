<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Drivers\LogDriver;
use Prism\Prism\Telemetry\Drivers\NullDriver;
use RuntimeException;

class TelemetryManager
{
    /** @var array<string, Closure> */
    protected array $customCreators = [];

    public function __construct(
        protected Application $app
    ) {}

    /**
     * @param  array<string, mixed>  $driverConfig
     *
     * @throws InvalidArgumentException
     */
    public function resolve(string $name, array $driverConfig = []): TelemetryDriver
    {
        $config = array_merge($this->getConfig($name), $driverConfig);

        if (isset($this->customCreators[$name])) {
            return $this->callCustomCreator($name, $config);
        }

        $factory = sprintf('create%sDriver', ucfirst($name));

        if (method_exists($this, $factory)) {
            return $this->{$factory}($config);
        }

        throw new InvalidArgumentException("Telemetry driver [{$name}] is not supported.");
    }

    /**
     * @throws RuntimeException
     */
    public function extend(string $driver, Closure $callback): self
    {
        if (($callback = $callback->bindTo($this, $this)) instanceof \Closure) {
            $this->customCreators[$driver] = $callback;

            return $this;
        }

        throw new RuntimeException(
            sprintf('Couldn\'t bind %s', $driver)
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createNullDriver(array $config): NullDriver
    {
        return new NullDriver;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createLogDriver(array $config): LogDriver
    {
        return new LogDriver(
            channel: $config['channel'] ?? 'default'
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function callCustomCreator(string $driver, array $config): TelemetryDriver
    {
        return $this->customCreators[$driver]($this->app, $config);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getConfig(string $name): array
    {
        return config("prism.telemetry.drivers.{$name}", []);
    }
}
