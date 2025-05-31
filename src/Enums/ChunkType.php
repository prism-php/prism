<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

enum ChunkType
{
    case Text;
    case Thinking;
    case Meta;
    case ToolCall;
    case ToolResult;
}
