<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Semantics;

use Prism\Prism\Telemetry\Contracts\SemanticMapperInterface;

/**
 * Passthrough mapper that returns attributes unchanged.
 *
 * Use this when sending raw Prism attributes without semantic conversion.
 */
class PassthroughMapper implements SemanticMapperInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function map(string $operation, array $attributes): array
    {
        return $attributes;
    }

    /**
     * @param  array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>  $events
     * @return array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>
     */
    public function mapEvents(array $events): array
    {
        return $events;
    }
}
