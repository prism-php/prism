<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

enum StreamEventType: string
{
    case StreamStart = 'stream_start';
    case TextStart = 'text_start';
    case TextDelta = 'text_delta';
    case TextComplete = 'text_complete';
    case ThinkingStart = 'thinking_start';
    case ThinkingDelta = 'thinking_delta';
    case ThinkingComplete = 'thinking_complete';
    case ToolCall = 'tool_call';
    case ToolResult = 'tool_result';
    case Citation = 'citation';
    case Error = 'error';
    case StreamEnd = 'stream_end';
}
