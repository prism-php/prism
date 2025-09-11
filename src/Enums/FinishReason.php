<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

enum FinishReason: string
{
    case Stop = 'stop';
    case Length = 'length';
    case ContentFilter = 'content-filter';
    case ToolCalls = 'tool-calls';
    case Error = 'error';
    case Other = 'other';
    case Unknown = 'unknown';
}
