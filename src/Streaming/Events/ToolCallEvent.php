<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;

readonly class ToolCallEvent extends StreamEvent
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        string $id,
        int $timestamp,
        public string $toolId,          // Tool call ID
        public string $toolName,        // Name of tool being called
        public array $arguments,        // Tool arguments
        public string $messageId,       // Message this tool call belongs to
        public ?string $reasoningId = null, // Associated reasoning if available
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::ToolCall;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'tool_id' => $this->toolId,
            'tool_name' => $this->toolName,
            'arguments' => $this->arguments,
            'message_id' => $this->messageId,
            'reasoning_id' => $this->reasoningId,
        ];
    }
}
