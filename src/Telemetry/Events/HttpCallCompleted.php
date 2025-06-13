<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;

class HttpCallCompleted
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $spanId,
        public readonly string $method,
        public readonly string $url,
        public readonly int $statusCode,
        public readonly array $context = []
    ) {}
}
