<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Dispatched when a tool call completes successfully.
 *
 * @property-read string $spanId Unique identifier for this span (16 hex chars)
 * @property-read string $traceId Trace identifier linking related spans (32 hex chars)
 * @property-read string|null $parentSpanId Parent span ID for nested operations
 * @property-read ToolCall $toolCall The tool call that was executed
 * @property-read ToolResult $toolResult The result of the tool execution
 */
class ToolCallCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $spanId,
        public readonly string $traceId,
        public readonly ?string $parentSpanId,
        public readonly ToolCall $toolCall,
        public readonly ToolResult $toolResult,
    ) {}
}
