<?php

declare(strict_types=1);

namespace Prism\Prism\Events;

class ToolCallStarted extends TraceEvent
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $toolName,
        public readonly array $attributes
    ) {
        parent::__construct();
    }
}
