<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\ValueObjects;

enum SpanStatus: string
{
    case Ok = 'ok';
    case Error = 'error';
    case Timeout = 'timeout';
    case Cancelled = 'cancelled';
}
