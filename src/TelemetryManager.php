<?php

declare(strict_types=1);

namespace Prism\Prism;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Manager;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\LogTelemetryDriver;

class TelemetryManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return config('prism.telemetry.driver', 'log');
    }

    public function driver($driver = null): TelemetryDriver
    {
        return parent::driver($driver);
    }

    public function extend($driver, Closure $callback)
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createLogDriver(array $config = []): LogTelemetryDriver
    {
        $channel = $config['channel'] ?? config('prism.telemetry.drivers.log.channel', 'single');

        return new LogTelemetryDriver(
            Log::channel($channel)
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function configuration(string $name): array
    {
        return config("prism.telemetry.drivers.{$name}", []);
    }
}
