<?php

declare(strict_types=1);

namespace Prism\Prism\Events;

class HttpRequestCompleted extends TelemetryEvent
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        string $contextId,
        public readonly int $statusCode,
        public readonly ?\Throwable $exception = null,
        array $attributes = []
    ) {
        parent::__construct($contextId, null, $attributes);
    }
}
