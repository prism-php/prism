<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Drivers;

use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\SpanData;

/**
 * Null telemetry driver.
 *
 * Discards all telemetry data. Used when telemetry is disabled.
 */
class NullDriver implements TelemetryDriver
{
    /**
     * Record a span (no-op).
     */
    public function recordSpan(SpanData $span): void
    {
        // Intentionally empty - discards all telemetry
    }

    /**
     * Shutdown (no-op).
     */
    public function shutdown(): void
    {
        // Nothing to flush
    }
}
