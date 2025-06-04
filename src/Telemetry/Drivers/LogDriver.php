<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Drivers;

use Prism\Prism\Telemetry\Contracts\Span;
use Prism\Prism\Telemetry\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\ValueObjects\LogSpan;
use Prism\Prism\Telemetry\ValueObjects\SpanStatus;
use Throwable;

class LogDriver implements TelemetryDriver
{
    public function __construct(
        private readonly string $logChannel,
        private readonly string $logLevel,
        private readonly bool $includeAttributes
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function startSpan(string $name, array $attributes = [], ?float $startTime = null): Span
    {
        $span = new LogSpan(
            $name,
            $startTime ?? microtime(true),
            $this->logChannel,
            $this->logLevel
        );

        if ($this->includeAttributes && $attributes !== []) {
            $span->setAttributes($attributes);
        }

        return $span;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function span(string $name, array $attributes, callable $callback): mixed
    {
        $span = $this->startSpan($name, $attributes);

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
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
