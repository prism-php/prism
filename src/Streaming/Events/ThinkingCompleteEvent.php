<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;

readonly class ThinkingCompleteEvent extends StreamEvent
{
    /**
     * @param  array<string, mixed>|null  $summary
     */
    public function __construct(
        string $id,
        int $timestamp,
        public string $reasoningId,     // Unique reasoning session ID
        public ?array $summary = null,  // Optional reasoning summary
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::ThinkingComplete;
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
            'summary' => $this->summary,
        ];
    }
}
