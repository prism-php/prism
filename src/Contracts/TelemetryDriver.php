<?php

declare(strict_types=1);

namespace Prism\Prism\Contracts;

interface TelemetryDriver
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function startSpan(string $operation, array $attributes = []): string;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function endSpan(string $spanId, array $attributes = []): void;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addEvent(string $spanId, string $name, array $attributes = []): void;

    public function recordException(string $spanId, \Throwable $exception): void;
}
