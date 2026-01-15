<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when an HTTP request to a provider begins.
 *
 * @property-read string $spanId Unique identifier for this span (16 hex chars)
 * @property-read string $traceId Trace identifier linking related spans (32 hex chars)
 * @property-read string|null $parentSpanId Parent span ID for nested operations
 * @property-read string $method HTTP method (GET, POST, etc.)
 * @property-read string $url The request URL
 * @property-read array<string, mixed> $headers Request headers
 */
class HttpCallStarted
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $headers  Request headers
     */
    public function __construct(
        public readonly string $spanId,
        public readonly string $traceId,
        public readonly ?string $parentSpanId,
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
    ) {}
}
