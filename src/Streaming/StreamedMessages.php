<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming;

use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Rebuilds the messages a stream produced (one AssistantMessage per step,
 * carrying that step's replay metadata, followed by its ToolResultMessage)
 * so a streamed conversation can be stored and continued.
 */
class StreamedMessages
{
    protected string $text = '';

    /** @var ToolCall[] */
    protected array $toolCalls = [];

    /** @var ToolResult[] */
    protected array $toolResults = [];

    /** @var ToolApprovalRequest[] */
    protected array $toolApprovalRequests = [];

    /** @var Message[] */
    protected array $messages = [];

    protected bool $insideStep = false;

    protected bool $sawProviderTool = false;

    final protected function __construct() {}

    /**
     * @param  iterable<StreamEvent>  $events
     * @return Collection<int, Message>
     */
    public static function fromEvents(iterable $events): Collection
    {
        $builder = new static;

        foreach ($events as $event) {
            $builder->apply($event);
        }

        $builder->finishInterruptedStream();

        return new Collection($builder->messages);
    }

    protected function apply(StreamEvent $event): void
    {
        match (true) {
            $event instanceof StepStartEvent => $this->insideStep = true,
            $event instanceof StepFinishEvent => $this->finishStep($event),
            $event instanceof TextStartEvent => $this->startText(),
            $event instanceof TextDeltaEvent => $this->text .= $event->delta,
            $event instanceof ToolCallEvent => $this->toolCalls[] = $event->toolCall,
            $event instanceof ToolApprovalRequestEvent => $this->recordApproval($event),
            $event instanceof ToolResultEvent => $this->toolResults[] = $event->toolResult,
            $event instanceof ProviderToolEvent => $this->recordProviderTool(),
            $event instanceof StreamEndEvent => $this->flush(),
            default => null,
        };
    }

    /**
     * A step that reports a finish reason other than ToolCalls (a refusal, a
     * truncated or failed turn) streamed tool calls that were never executed.
     * They stay out of the assistant message, which would otherwise replay
     * them without results.
     */
    protected function finishStep(StepFinishEvent $event): void
    {
        $this->insideStep = false;

        if ($event->finishReason instanceof FinishReason && $event->finishReason !== FinishReason::ToolCalls) {
            $pending = array_map(fn (ToolApprovalRequest $request): string => $request->toolCallId, $this->toolApprovalRequests);
            $this->toolCalls = array_values(array_filter(
                $this->toolCalls,
                fn (ToolCall $call): bool => in_array($call->id, $pending, true)
            ));
        }

        $this->flush($event->additionalContent);
    }

    protected function recordApproval(ToolApprovalRequestEvent $event): void
    {
        // Some providers emit the call separately; the approval event also
        // carries it. Keep exactly one native call so its request can be
        // matched on a later turn without fabricating a tool result.
        if (! in_array($event->toolCall->id, array_map(fn (ToolCall $call): string => $call->id, $this->toolCalls), true)) {
            $this->toolCalls[] = $event->toolCall;
        }

        $this->toolApprovalRequests[] = new ToolApprovalRequest($event->approvalId, $event->toolCall->id);
    }

    /**
     * Streams without step events start a new assistant message for each
     * text block.
     */
    protected function startText(): void
    {
        if (! $this->insideStep) {
            $this->flush();
        }
    }

    /**
     * Streams without step events finish the text before the first provider
     * tool call, to keep it ahead of the tool in the message order.
     */
    protected function recordProviderTool(): void
    {
        if (! $this->insideStep && $this->text !== '' && ! $this->sawProviderTool) {
            $this->flush();
        }

        $this->sawProviderTool = true;
    }

    /**
     * A stream that stopped early (a closed connection, an exception) keeps
     * what it produced, minus tool calls that never got a result.
     */
    protected function finishInterruptedStream(): void
    {
        $answered = array_map(fn (ToolResult $result): string => $result->toolCallId, $this->toolResults);
        $pending = array_map(fn (ToolApprovalRequest $request): string => $request->toolCallId, $this->toolApprovalRequests);

        $this->toolCalls = array_values(array_filter(
            $this->toolCalls,
            fn (ToolCall $toolCall): bool => in_array($toolCall->id, $answered, true) || in_array($toolCall->id, $pending, true)
        ));

        $this->flush();
    }

    /**
     * A step without text or tool calls still produces an assistant message
     * when the provider attached ordered replay content to it.
     *
     * @param  array<string, mixed>  $additionalContent
     */
    protected function flush(array $additionalContent = []): void
    {
        if ($this->text !== '' || $this->toolCalls !== [] || $this->toolApprovalRequests !== [] || $this->hasReplayContent($additionalContent)) {
            $this->messages[] = new AssistantMessage($this->text, $this->toolCalls, $additionalContent, $this->toolApprovalRequests);
            $this->text = '';
            $this->toolCalls = [];
            $this->toolApprovalRequests = [];
        }

        if ($this->toolResults !== []) {
            $this->messages[] = new ToolResultMessage($this->toolResults);
            $this->toolResults = [];
        }
    }

    /**
     * @param  array<string, mixed>  $additionalContent
     */
    protected function hasReplayContent(array $additionalContent): bool
    {
        return ! empty($additionalContent['content_blocks'])
            || ! empty($additionalContent['output_items']);
    }
}
