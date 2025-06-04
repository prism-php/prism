<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Facades;

use Illuminate\Support\Facades\Facade;
use Prism\Prism\Telemetry\Contracts\Span;

/**
 * @method static Span startSpan(string $name, array $attributes = [], ?float $startTime = null)
 * @method static mixed span(string $name, array $attributes, callable $callback)
 * @method static bool enabled()
 * @method static Span|null current()
 * @method static mixed withCurrentSpan(Span $span, callable $callback)
 */
class Telemetry extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'prism-telemetry';
    }
}
