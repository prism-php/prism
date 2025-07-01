<?php

declare(strict_types=1);

namespace Prism\Prism\Events;

class HttpRequestStarted extends TraceEvent
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  string[][]  $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly array $attributes,
    ) {
        parent::__construct();
    }
}
