<?php

declare(strict_types=1);

namespace Prism\Prism\Events;

abstract class TelemetryEvent
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $contextId,
        public readonly ?string $parentContextId = null,
        public readonly array $attributes = []
    ) {}
}
