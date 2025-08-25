<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

enum StreamEventType: string
{
    case StreamStart = 'stream-start';
    case TextStart = 'text-start';
    case TextDelta = 'text-delta';
    case TextComplete = 'text-complete';
    case ThinkingStart = 'thinking-start';
    case ThinkingDelta = 'thinking-delta';
    case ThinkingComplete = 'thinking-complete';
    case ToolCall = 'tool-call';
    case ToolResult = 'tool-result';
    case Error = 'error';
    case StreamEnd = 'stream-end';
}
