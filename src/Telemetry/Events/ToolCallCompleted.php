<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class ToolCallCompleted
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $spanId,
        public readonly ToolCall $toolCall,
        public readonly ToolResult $toolResult,
        public readonly array $context = []
    ) {}
}
