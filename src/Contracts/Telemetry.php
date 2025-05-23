<?php

declare(strict_types=1);

namespace Prism\Prism\Contracts;

interface Telemetry
{
    /**
     * Check if telemetry is enabled.
     */
    public function enabled(): bool;

    /**
     * Create a span and execute a callback within its context.
     *
     * @template T
     *
     * @param  non-empty-string  $spanName
     * @param  array<non-empty-string, mixed>  $attributes
     * @param  callable(): T  $callback
     * @return T
     */
    public function span(string $spanName, array $attributes, callable $callback): mixed;

    /**
     * Create a child span with the current context as parent.
     *
     * @template T
     *
     * @param  non-empty-string  $spanName
     * @param  array<non-empty-string, mixed>  $attributes
     * @param  callable(): T  $callback
     * @return T
     */
    public function childSpan(string $spanName, array $attributes, callable $callback): mixed;
}
