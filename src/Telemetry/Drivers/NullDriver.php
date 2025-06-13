<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Drivers;

use Prism\Prism\Contracts\TelemetryDriver;

class NullDriver implements TelemetryDriver
{
    public function startSpan(string $operation, array $attributes = []): string
    {
        return '';
    }

    public function endSpan(string $spanId, array $attributes = []): void
    {
        //
    }

    public function addEvent(string $spanId, string $name, array $attributes = []): void
    {
        //
    }

    public function recordException(string $spanId, \Throwable $exception): void
    {
        //
    }
}
