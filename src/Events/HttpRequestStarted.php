<?php

declare(strict_types=1);

namespace Prism\Prism\Events;

class HttpRequestStarted extends TelemetryEvent
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        string $contextId,
        public readonly string $method,
        public readonly string $url,
        public readonly string $provider,
        ?string $parentContextId = null,
        array $attributes = []
    ) {
        parent::__construct($contextId, $parentContextId, $attributes);
    }
}
