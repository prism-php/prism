<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Drivers;

use Prism\Prism\Telemetry\Contracts\Span;
use Prism\Prism\Telemetry\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\ValueObjects\NullSpan;

class NullDriver implements TelemetryDriver
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function startSpan(string $name, array $attributes = [], ?float $startTime = null): Span
    {
        return new NullSpan($name, $startTime ?? microtime(true));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function span(string $name, array $attributes, callable $callback): mixed
    {
        return $callback();
    }
}
