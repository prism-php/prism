<?php

declare(strict_types=1);

namespace Prism\Prism\Contracts;

use Prism\Prism\Telemetry\SpanData;

interface TelemetryDriver
{
    /**
     * Record a complete span.
     *
     * The driver receives fully constructed span data and is responsible
     * for formatting and exporting it to the appropriate backend.
     */
    public function recordSpan(SpanData $span): void;

    /**
     * Shutdown the driver, flushing any buffered spans.
     *
     * Called when a Prism operation completes to ensure all
     * telemetry data is exported.
     */
    public function shutdown(): void;
}
