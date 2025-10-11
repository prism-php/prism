<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class StreamCollector
{
    /**
     * @param  null|Closure(PendingRequest|null, Collection<int,Message>):void  $onCompleteCallback
     */
    public function __construct(
        protected Generator $stream,
        protected ?\Prism\Prism\Text\PendingRequest $pendingRequest = null,
        protected ?Closure $onCompleteCallback = null
    ) {}

    /**
     * @return Generator<\Prism\Prism\Streaming\Events\StreamEvent>
     */
    public function collect(): Generator
    {
        $accumulatedText = '';
        /** @var ToolCall[] $toolCalls */
        $toolCalls = [];
        /** @var ToolResult[] $toolResults */
        $toolResults = [];
        /** @var Message[] $messages */
        $messages = [];

        foreach ($this->stream as $event) {
            yield $event;

            if ($event instanceof TextStartEvent) {
                $this->handleTextStart($accumulatedText, $toolCalls, $toolResults, $messages);
            } elseif ($event instanceof TextDeltaEvent) {
                $accumulatedText .= $event->delta;
            } elseif ($event instanceof ToolCallEvent) {
                $toolCalls[] = $event->toolCall;
            } elseif ($event instanceof ToolResultEvent) {
                $toolResults[] = $event->toolResult;
            } elseif ($event instanceof StreamEndEvent) {
                $this->handleStreamEnd($accumulatedText, $toolCalls, $toolResults, $messages);
            }
        }
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  Message[]  $messages
     */
    protected function handleTextStart(
        string &$accumulatedText,
        array &$toolCalls,
        array &$toolResults,
        array &$messages
    ): void {
        $this->finalizeCurrentMessage($accumulatedText, $toolCalls, $toolResults, $messages);
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  Message[]  $messages
     */
    protected function handleStreamEnd(
        string &$accumulatedText,
        array &$toolCalls,
        array &$toolResults,
        array &$messages
    ): void {
        $this->finalizeCurrentMessage($accumulatedText, $toolCalls, $toolResults, $messages);

        if ($this->onCompleteCallback instanceof Closure) {
            ($this->onCompleteCallback)($this->pendingRequest, collect($messages));
        }
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  Message[]  $messages
     */
    protected function finalizeCurrentMessage(
        string &$accumulatedText,
        array &$toolCalls,
        array &$toolResults,
        array &$messages
    ): void {
        if ($accumulatedText !== '' || $toolCalls !== []) {
            $messages[] = new AssistantMessage($accumulatedText, $toolCalls);
            $accumulatedText = '';
            $toolCalls = [];
        }

        if ($toolResults !== []) {
            $messages[] = new ToolResultMessage($toolResults);
            $toolResults = [];
        }
    }
}
