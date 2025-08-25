<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;

readonly class ToolResultEvent extends StreamEvent
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        string $id,
        int $timestamp,
        public string $toolId,          // Tool call ID this result belongs to
        public array $result,           // Tool execution result
        public string $messageId,       // Message this belongs to
        public bool $success = true,    // Whether tool execution succeeded
        public ?string $error = null,   // Error message if failed
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
            'tool_id' => $this->toolId,
            'result' => $this->result,
            'message_id' => $this->messageId,
            'success' => $this->success,
            'error' => $this->error,
        ];
    }
}
