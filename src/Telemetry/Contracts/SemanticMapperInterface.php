<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Contracts;

/**
 * Interface for semantic convention mappers.
 *
 * Mappers convert generic Prism span attributes to specific semantic conventions
 * like OpenInference (Phoenix/Arize) or GenAI (Langfuse).
 */
interface SemanticMapperInterface
{
    /**
     * Map generic Prism attributes to semantic convention format.
     *
     * @param  string  $operation  The operation type (e.g., 'text_generation', 'tool_call')
     * @param  array<string, mixed>  $attributes  Generic Prism attributes
     * @return array<string, mixed> Mapped attributes in semantic convention format
     */
    public function map(string $operation, array $attributes): array;

    /**
     * Map events to semantic convention format.
     *
     * @param  array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>  $events
     * @return array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>
     */
    public function mapEvents(array $events): array;
}
