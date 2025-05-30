<?php

declare(strict_types=1);

namespace Prism\Prism\Contracts;

interface TelemetryDriver
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function startSpan(string $name, array $attributes = [], ?string $parentId = null): string;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function endSpan(string $contextId, array $attributes = []): void;

    public function recordException(string $contextId, \Throwable $exception): void;
}
