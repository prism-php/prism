<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Illuminate\Support\Manager;
use Prism\Prism\Telemetry\Contracts\Span;
use Prism\Prism\Telemetry\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Drivers\LogDriver;
use Prism\Prism\Telemetry\Drivers\NullDriver;
use Prism\Prism\Telemetry\ValueObjects\SpanStatus;
use Throwable;

/**
 * @mixin TelemetryDriver
 */
class TelemetryManager extends Manager
{
    private ?Span $currentSpan = null;

    public function getDefaultDriver(): string
    {
        return $this->config->get('prism.telemetry.default', 'null');
    }

    public function createNullDriver(): NullDriver
    {
        return new NullDriver;
    }

    public function createLogDriver(): LogDriver
    {
        return new LogDriver(
            logChannel: config('prism.telemetry.drivers.log.channel'),
            logLevel: config('prism.telemetry.drivers.log.level'),
            includeAttributes: config('prism.telemetry.drivers.log.include_attributes')
        );
    }

    public function enabled(): bool
    {
        return $this->config->get('prism.telemetry.enabled', true);
    }

    public function current(): ?Span
    {
        return $this->currentSpan;
    }

    public function withCurrentSpan(Span $span, callable $callback): mixed
    {
        $previousSpan = $this->currentSpan;
        $this->currentSpan = $span;

        try {
            return $callback();
        } finally {
            $this->currentSpan = $previousSpan;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function startSpan(string $name, array $attributes = [], ?float $startTime = null): Span
    {
        if (! $this->enabled()) {
            return $this->createNullDriver()->startSpan($name, $attributes, $startTime);
        }

        return $this->driver()->startSpan($name, $attributes, $startTime);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function span(string $name, array $attributes, callable $callback): mixed
    {
        if (! $this->enabled()) {
            return $callback();
        }

        $span = $this->startSpan($name, $attributes);

        return $this->withCurrentSpan($span, function () use ($span, $callback) {
            try {
                $result = $callback();
                $span->setStatus(SpanStatus::Ok);

                return $result;
            } catch (Throwable $e) {
                $span->setStatus(SpanStatus::Error, $e->getMessage());
                throw $e;
            } finally {
                $span->end();
            }
        });
    }
}
