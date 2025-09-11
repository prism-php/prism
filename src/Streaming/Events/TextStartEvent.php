<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;

readonly class TextStartEvent extends StreamEvent
{
    public function __construct(
        string $id,
        int $timestamp,
        public string $messageId,      // Message ID that's starting
        public ?string $turnId = null, // Turn ID for multi-turn conversations
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::TextStart;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'message_id' => $this->messageId,
            'turn_id' => $this->turnId,
        ];
    }
}
