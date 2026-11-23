<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Concerns\CopiesWithProviderOptions;
use Prism\Prism\Concerns\HasCacheHint;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\DeclaresCacheStability;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Support\JsonMap;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * @implements Arrayable<string, mixed>
 */
class AssistantMessage implements Arrayable, DeclaresCacheStability, Message
{
    use CopiesWithProviderOptions;
    use HasCacheHint;
    use HasProviderOptions;

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  array<string,mixed>  $additionalContent
     * @param  ToolApprovalRequest[]  $toolApprovalRequests  Approval requests for approval-required tools (not sent to the LLM; used for correlation)
     */
    public function __construct(
        public readonly string $content,
        public readonly array $toolCalls = [],
        public readonly array $additionalContent = [],
        public readonly array $toolApprovalRequests = []
    ) {}

    /**
     * A copy carrying these tool calls, and everything else this message holds.
     *
     * @param  ToolCall[]  $toolCalls
     */
    public function withToolCalls(array $toolCalls): self
    {
        $copy = new self($this->content, $toolCalls, $this->additionalContent, $this->toolApprovalRequests);
        $copy->providerOptions = $this->providerOptions;
        $copy->cacheStability = $this->cacheStability;
        $copy->cacheTtl = $this->cacheTtl;

        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'type' => 'assistant',
            'content' => $this->content,
            'tool_calls' => array_map(fn (ToolCall $toolCall): array => $toolCall->toArray(), $this->toolCalls),
            'additional_content' => JsonMap::of($this->additionalContent),
            'tool_approval_requests' => array_map(fn (ToolApprovalRequest $request): array => $request->toArray(), $this->toolApprovalRequests),
        ];
    }
}
