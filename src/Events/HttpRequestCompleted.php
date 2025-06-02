<?php

declare(strict_types=1);

namespace Prism\Prism\Events;

class HttpRequestCompleted extends TraceEvent
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  string[][]  $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly array $attributes,
        public readonly ?\Throwable $exception = null
    ) {
        parent::__construct();
    }
}
