<?php

declare(strict_types=1);

namespace Prism\Prism\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Prism\Prism\Support\Trace;

/**
 * @property-read array{traceId:string, parentTraceId:string|null, traceName:string, startTime:float, endTime:float|null}|null $trace
 */
class TraceEvent
{
    use Dispatchable, SerializesModels;

    /** @var array{traceId:string, parentTraceId:string|null, traceName:string, startTime:float, endTime:float|null}|null */
    public readonly ?array $trace;

    public function __construct()
    {
        $this->trace = Trace::get();
    }
}
