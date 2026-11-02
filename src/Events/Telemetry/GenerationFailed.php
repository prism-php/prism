<?php

declare(strict_types=1);

namespace Prism\Prism\Events\Telemetry;

use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\StreamFailureUsage;
use Throwable;

/**
 * Dispatched when a generation terminates with an exception.
 */
readonly class GenerationFailed
{
    public function __construct(
        public TelemetryContext $context,
        public float $durationMs,
        public Throwable $exception,
        public ?StreamFailureUsage $failureUsage = null,
    ) {}
}
