<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

enum FinishReason
{
    case Stop;
    case Length;
    case ContentFilter;
    case ToolCalls;
    case Error;
    case Other;
    case Unknown;
}
