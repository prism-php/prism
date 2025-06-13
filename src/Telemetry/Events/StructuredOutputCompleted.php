<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response;

class StructuredOutputCompleted
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $spanId,
        public readonly Request $request,
        public readonly Response $response,
        public readonly array $context = []
    ) {}
}
