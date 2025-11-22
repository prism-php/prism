<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\ValueObjects\Citation;

readonly class CitationEvent extends StreamEvent
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        string $id,
        int $timestamp,
        public Citation $citation,           // The citation object
        public string $messageId,            // Message this citation belongs to
        public ?int $blockIndex = null,      // Content block index for this citation
        public ?array $metadata = null       // Additional citation metadata
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::Citation;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'citation' => [
                'source_type' => $this->citation->sourceType->value,
                'source' => $this->citation->source,
                'source_text' => $this->citation->sourceText,
                'source_title' => $this->citation->sourceTitle,
                'source_position_type' => $this->citation->sourcePositionType?->value,
                'source_start_index' => $this->citation->sourceStartIndex,
                'source_end_index' => $this->citation->sourceEndIndex,
                'additional_content' => $this->citation->additionalContent,
            ],
            'message_id' => $this->messageId,
            'block_index' => $this->blockIndex,
            'metadata' => $this->metadata,
        ];
    }
}
