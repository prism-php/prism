<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\ValueObjects\ToolResult;

readonly class ToolResultEvent extends StreamEvent
{
    public function __construct(
        string $id,
        int $timestamp,
        public ToolResult $toolResult,   // Tool result value object
        public string $messageId,        // Message this belongs to
        public bool $success = true,     // Whether tool execution succeeded
        public ?string $error = null,    // Error message if failed
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::ToolResult;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'tool_id' => $this->toolResult->toolCallId,
            'result' => $this->toolResult->result,
            'message_id' => $this->messageId,
            'success' => $this->success,
            'error' => $this->error,
        ];
    }
}
