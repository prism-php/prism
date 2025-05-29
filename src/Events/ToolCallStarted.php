<?php

declare(strict_types=1);

namespace Prism\Prism\Events;

class ToolCallStarted extends TelemetryEvent
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        string $contextId,
        public readonly string $toolName,
        public readonly array $parameters,
        ?string $parentContextId = null,
        array $attributes = []
    ) {
        parent::__construct($contextId, $parentContextId, $attributes);
    }
}
