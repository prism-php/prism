<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;

readonly class ThinkingStartEvent extends StreamEvent
{
    public function __construct(
        string $id,
        int $timestamp,
        public string $reasoningId,     // Unique reasoning session ID
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::ThinkingStart;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'reasoning_id' => $this->reasoningId,
        ];
    }
}
