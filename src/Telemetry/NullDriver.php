<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Prism\Prism\Contracts\Telemetry;

class NullDriver implements Telemetry
{
    public function enabled(): bool
    {
        return false;
    }

    public function span(string $spanName, array $attributes, callable $callback): mixed
    {
        return $callback();
    }

    public function childSpan(string $spanName, array $attributes, callable $callback): mixed
    {
        return $callback();
    }
}
