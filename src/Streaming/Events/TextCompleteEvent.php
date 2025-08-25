<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;

readonly class TextCompleteEvent extends StreamEvent
{
    public function __construct(
        string $id,
        int $timestamp,
        public string $messageId,      // Message ID that's now complete
        public ?string $turnId = null,
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::TextComplete;
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
