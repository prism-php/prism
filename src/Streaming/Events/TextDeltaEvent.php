<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;

readonly class TextDeltaEvent extends StreamEvent
{
    public function __construct(
        string $id,
        int $timestamp,
        public string $delta,
        public string $messageId,
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::TextDelta;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'delta' => $this->delta,
            'message_id' => $this->messageId,
        ];
    }
}
