<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * Dispatched when a tool call begins execution.
 *
 * @property-read string $spanId Unique identifier for this span (16 hex chars)
 * @property-read string $traceId Trace identifier linking related spans (32 hex chars)
 * @property-read string|null $parentSpanId Parent span ID for nested operations
 * @property-read ToolCall $toolCall The tool call being executed
 * @property-read Tool|null $tool The resolved tool instance (if available)
 */
class ToolCallStarted
{
    use Dispatchable;

    public function __construct(
        public readonly string $spanId,
        public readonly string $traceId,
        public readonly ?string $parentSpanId,
        public readonly ToolCall $toolCall,
        public readonly ?Tool $tool = null,
    ) {}
}
