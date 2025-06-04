<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Contracts;

interface TelemetryDriver
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function startSpan(string $name, array $attributes = [], ?float $startTime = null): Span;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function span(string $name, array $attributes, callable $callback): mixed;

    public function isEnabled(): bool;
}
