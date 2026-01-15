<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Drivers;

use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Jobs\ExportSpanJob;
use Prism\Prism\Telemetry\SpanData;

/**
 * Generic OTLP telemetry driver.
 *
 * Exports spans to any OTLP-compatible endpoint with configurable semantic mapping.
 */
class OtlpDriver implements TelemetryDriver
{
    public function __construct(
        protected string $driver = 'otlp'
    ) {}

    public function recordSpan(SpanData $span): void
    {
        $config = config("prism.telemetry.drivers.{$this->driver}", []);

        if ($config['sync'] ?? false) {
            (new ExportSpanJob($span, $this->driver))->handle();
        } else {
            ExportSpanJob::dispatch($span, $this->driver);
        }
    }

    public function getDriver(): string
    {
        return $this->driver;
    }
}
