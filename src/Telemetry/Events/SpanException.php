<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

/**
 * Dispatched when an exception occurs during a span's execution.
 *
 * This allows the span collector to record the exception on the span
 * before the exception propagates up the call stack.
 *
 * @property-read string $spanId The span ID where the exception occurred (16 hex chars)
 * @property-read Throwable $exception The exception that was thrown
 * @property-read int $timeNanos Unix epoch timestamp in nanoseconds
 */
class SpanException
{
    use Dispatchable;

    public readonly int $timeNanos;

    public function __construct(
        public readonly string $spanId,
        public readonly Throwable $exception,
        ?int $timeNanos = null,
    ) {
        $this->timeNanos = $timeNanos ?? now_nanos();
    }
}
