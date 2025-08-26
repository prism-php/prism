<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Adapters;

use Generator;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataProtocolAdapter
{
    public function __construct(
        protected Generator $events
    ) {}

    public function asDataStreamResponse(): StreamedResponse
    {
        return response()->stream(function (): void {
            foreach ($this->events as $event) {
                if (connection_aborted() !== 0) {
                    break;
                }

                $data = $this->handleEventConversion($event);

                echo "data: {$data}\n\n";

                ob_flush();
                flush();
            }

            echo "data: [DONE]\n\n";
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'x-vercel-ai-ui-message-stream' => 'v1',
        ]);
    }

    protected function handleEventConversion(StreamEvent $event): string
    {
        $data = match ($event::class) {
            StreamStartEvent::class => $this->handleStreamStart($event),
            TextStartEvent::class => $this->handleTextStart($event),
            TextDeltaEvent::class => $this->handleTextDelta($event),
            TextCompleteEvent::class => $this->handleTextComplete($event),
            ThinkingStartEvent::class => $this->handleThinkingStart($event),
            ThinkingEvent::class => $this->handleThinkingDelta($event),
            ThinkingCompleteEvent::class => $this->handleThinkingComplete($event),
            ToolCallEvent::class => $this->handleToolCall($event),
            ToolResultEvent::class => $this->handleToolResult($event),
            StreamEndEvent::class => $this->handleStreamEnd($event),
            ErrorEvent::class => $this->handleError($event),
            default => $this->handleDefault($event),
        };

        return json_encode($data);
    }

    protected function handleStreamStart(StreamStartEvent $event): array
    {
        return [
            'type' => 'start',
            'messageId' => $event->id,
        ];
    }

    protected function handleTextStart(TextStartEvent $event): array
    {
        return [
            'type' => 'text-start',
            'id' => $event->messageId,
        ];
    }

    protected function handleTextDelta(TextDeltaEvent $event): array
    {
        return [
            'type' => 'text-delta',
            'id' => $event->messageId,
            'delta' => $event->delta,
        ];
    }

    protected function handleTextComplete(TextCompleteEvent $event): array
    {
        return [
            'type' => 'text-end',
            'id' => $event->messageId,
        ];
    }

    protected function handleThinkingStart(ThinkingStartEvent $event): array
    {
        return [
            'type' => 'reasoning-start',
            'id' => $event->reasoningId,
        ];
    }

    protected function handleThinkingDelta(ThinkingEvent $event): array
    {
        return [
            'type' => 'reasoning-delta',
            'id' => $event->reasoningId,
            'delta' => $event->delta,
        ];
    }

    protected function handleThinkingComplete(ThinkingCompleteEvent $event): array
    {
        return [
            'type' => 'reasoning-end',
            'id' => $event->reasoningId,
        ];
    }

    protected function handleToolCall(ToolCallEvent $event): array
    {
        return [
            'type' => 'tool-input-available',
            'toolCallId' => $event->toolId,
            'toolName' => $event->toolName,
            'input' => $event->arguments,
        ];
    }

    protected function handleToolResult(ToolResultEvent $event): array
    {
        return [
            'type' => 'tool-output-available',
            'toolCallId' => $event->toolId,
            'output' => $event->result,
        ];
    }

    protected function handleStreamEnd(StreamEndEvent $event): array
    {
        return [
            'type' => 'finish',
            'finishReason' => $event->finishReason->value,
            'usage' => $event->usage?->toArray(),
        ];
    }

    protected function handleError(ErrorEvent $event): array
    {
        return [
            'type' => 'error',
            'errorText' => $event->message,
        ];
    }

    protected function handleDefault(StreamEvent $event): array
    {
        return [
            'type' => 'data-'.$event->type()->value,
            'data' => $event->toArray(),
        ];
    }
}
