<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;

abstract readonly class StreamEvent
{
    public function __construct(
        public string $id,           // Unique event ID for tracking
        public int $timestamp,       // Unix timestamp when event created
    ) {}

    abstract public function type(): StreamEventType;

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    public function eventKey(): string
    {
        return $this->type()->value;
    }
}
