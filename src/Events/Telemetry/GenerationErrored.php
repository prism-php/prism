<?php

declare(strict_types=1);

namespace Prism\Prism\Events\Telemetry;

use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\StreamFailureUsage;

/** A stream reported failure as an event, without inventing a Throwable. */
readonly class GenerationErrored
{
    public string $status;

    public function __construct(
        public TelemetryContext $context,
        public float $durationMs,
        public ?StreamFailureUsage $failureUsage = null,
    ) {
        $this->status = 'error';
    }
}
