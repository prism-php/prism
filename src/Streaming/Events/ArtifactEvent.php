<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\ValueObjects\Artifact;

readonly class ArtifactEvent extends StreamEvent
{
    public function __construct(
        string $id,
        int $timestamp,
        public Artifact $artifact,
        public string $toolCallId,
        public string $toolName,
        public string $messageId,
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::Artifact;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'tool_call_id' => $this->toolCallId,
            'tool_name' => $this->toolName,
            'message_id' => $this->messageId,
            'artifact' => $this->artifact->toArray(),
        ];
    }
}
