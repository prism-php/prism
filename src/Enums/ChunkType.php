<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

enum ChunkType: string
{
    case Text = 'text';
    case Thinking = 'thinking';
    case Meta = 'meta';
    case ToolCall = 'tool_call';
    case ToolResult = 'tool_result';
}
