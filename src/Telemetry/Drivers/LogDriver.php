<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Drivers;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\SpanData;
use Throwable;

/**
 * Log telemetry driver.
 *
 * Logs generic Prism span attributes directly for debugging and development.
 * Uses human-readable attribute names (not OpenInference).
 */
class LogDriver implements TelemetryDriver
{
    /**
     * @param  string  $channel  Laravel log channel name
     */
    public function __construct(
        protected string $channel = 'default'
    ) {}

    /**
     * Log a completed span with readable attributes.
     */
    public function recordSpan(SpanData $span): void
    {
        Log::channel($this->channel)->info('Span recorded', [
            'span_id' => $span->spanId,
            'trace_id' => $span->traceId,
            'parent_span_id' => $span->parentSpanId,
            'operation' => $span->operation,
            'start_time_nano' => $span->startTimeNano,
            'end_time_nano' => $span->endTimeNano,
            'duration_ms' => $span->durationMs(),
            'attributes' => $span->attributes,
            'events' => $span->events,
            'has_error' => $span->hasError(),
            'exception' => $span->exception instanceof Throwable ? [
                'class' => $span->exception::class,
                'message' => $span->exception->getMessage(),
                'file' => $span->exception->getFile(),
                'line' => $span->exception->getLine(),
            ] : null,
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Shutdown (no-op for log driver - logs immediately).
     */
    public function shutdown(): void
    {
        // Log driver writes immediately, nothing to flush
    }
}
