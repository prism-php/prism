<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolCall;

readonly class ToolApprovalRequestEvent extends StreamEvent
{
    public function __construct(
        string $id,
        int $timestamp,
        public ToolCall $toolCall,
        public string $messageId,
        public string $approvalId,
    ) {
        parent::__construct($id, $timestamp);
    }

    /**
     * The request to store with the assistant message, marked as issued by
     * this version. Build it here rather than from the two ids, or the
     * stored request has no marker and is expired on resume when ambiguous.
     */
    public function approvalRequest(): ToolApprovalRequest
    {
        return ToolApprovalRequest::issue($this->approvalId, $this->toolCall->id);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::ToolApprovalRequest;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'approval_id' => $this->approvalId,
            'tool_id' => $this->toolCall->id,
            'tool_name' => $this->toolCall->name,
            'arguments' => $this->toolCall->withoutMalformedArguments()->argumentsAsObject(),
            ...$this->toolCall->hasMalformedArguments() ? ['arguments_malformed' => true] : [],
            'message_id' => $this->messageId,
        ];
    }
}
