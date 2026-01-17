<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

/**
 * Immutable DTO representing a complete telemetry span.
 *
 * Contains driver-agnostic attributes that can be converted to any
 * telemetry format (OpenInference, OpenTelemetry gen_ai, etc.) by drivers.
 */
readonly class SpanData
{
    /**
     * @param  string  $spanId  Unique identifier for this span
     * @param  string  $traceId  Trace identifier linking related spans
     * @param  string|null  $parentSpanId  Parent span ID for hierarchical traces
     * @param  string  $operation  The operation name (e.g., text_generation, tool_call)
     * @param  int  $startTimeNano  Start time in Unix epoch nanoseconds
     * @param  int  $endTimeNano  End time in Unix epoch nanoseconds
     * @param  array<string, mixed>  $attributes  Driver-agnostic span attributes
     * @param  array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>  $events  Mid-span events (exceptions, annotations)
     * @param  \Throwable|null  $exception  Exception if the span failed
     */
    public function __construct(
        public string $spanId,
        public string $traceId,
        public ?string $parentSpanId,
        public string $operation,
        public int $startTimeNano,
        public int $endTimeNano,
        public array $attributes,
        public array $events = [],
        public ?\Throwable $exception = null,
    ) {}

    /**
     * Check if the span has an error.
     */
    public function hasError(): bool
    {
        return $this->exception instanceof \Throwable;
    }

    /**
     * Get the duration in nanoseconds.
     */
    public function durationNano(): int
    {
        return $this->endTimeNano - $this->startTimeNano;
    }

    /**
     * Get the duration in milliseconds.
     */
    public function durationMs(): float
    {
        return $this->durationNano() / 1_000_000;
    }
}
