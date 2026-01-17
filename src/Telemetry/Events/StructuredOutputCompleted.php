<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response;

/**
 * Dispatched when structured output generation completes successfully.
 *
 * @property-read string $spanId Unique identifier for this span (16 hex chars)
 * @property-read string $traceId Trace identifier linking related spans (32 hex chars)
 * @property-read string|null $parentSpanId Parent span ID for nested operations
 * @property-read Request $request The structured output request
 * @property-read Response $response The generated structured response
 * @property-read int $timeNanos Unix epoch timestamp in nanoseconds
 */
class StructuredOutputCompleted
{
    use Dispatchable;

    public readonly int $timeNanos;

    public function __construct(
        public readonly string $spanId,
        public readonly string $traceId,
        public readonly ?string $parentSpanId,
        public readonly Request $request,
        public readonly Response $response,
        ?int $timeNanos = null,
    ) {
        $this->timeNanos = $timeNanos ?? now_nanos();
    }
}
