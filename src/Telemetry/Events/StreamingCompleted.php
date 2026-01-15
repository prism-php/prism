<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Text\Request;

/**
 * Dispatched when streaming text generation completes.
 *
 * @property-read string $spanId Unique identifier for this span (16 hex chars)
 * @property-read string $traceId Trace identifier linking related spans (32 hex chars)
 * @property-read string|null $parentSpanId Parent span ID for nested operations
 * @property-read Request $request The streaming request
 * @property-read StreamEndEvent|null $streamEnd The stream end event with usage/finishReason
 */
class StreamingCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $spanId,
        public readonly string $traceId,
        public readonly ?string $parentSpanId,
        public readonly Request $request,
        public readonly ?StreamEndEvent $streamEnd = null,
    ) {}
}
