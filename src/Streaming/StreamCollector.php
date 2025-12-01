<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderToolCall;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class StreamCollector
{
    /**
     * @param  null|Closure(PendingRequest|null, Collection<int,Message>, Response):void  $onCompleteCallback
     */
    public function __construct(
        protected Generator $stream,
        protected ?PendingRequest $pendingRequest = null,
        protected ?Closure $onCompleteCallback = null
    ) {}

    /**
     * @return Generator<StreamEvent>
     */
    public function collect(): Generator
    {
        $accumulatedText = '';
        /** @var ToolCall[] $toolCalls */
        $toolCalls = [];
        /** @var ToolResult[] $toolResults */
        $toolResults = [];
        /** @var ProviderToolCall[] $providerToolCalls */
        $providerToolCalls = [];
        /** @var Message[] $messages */
        $messages = [];
        $finishReason = FinishReason::Stop;
        $usage = null;
        $additionalContent = [];

        foreach ($this->stream as $event) {
            yield $event;

            if ($event instanceof TextStartEvent) {
                $this->handleTextStart($accumulatedText, $toolCalls, $toolResults, $providerToolCalls, $messages);
            } elseif ($event instanceof TextDeltaEvent) {
                $accumulatedText .= $event->delta;
            } elseif ($event instanceof ToolCallEvent) {
                $toolCalls[] = $event->toolCall;
            } elseif ($event instanceof ToolResultEvent) {
                $toolResults[] = $event->toolResult;
            } elseif ($event instanceof ProviderToolEvent) {
                // Finalize any accumulated text before tool events to preserve order
                if ($accumulatedText !== '' && empty($providerToolCalls)) {
                    $this->finalizeCurrentMessage($accumulatedText, $toolCalls, $toolResults, $providerToolCalls, $messages);
                }

                $providerToolCalls[] = new ProviderToolCall(
                    id: $event->itemId,
                    type: $event->toolType,
                    status: $event->status,
                    data: $event->data
                );
            } elseif ($event instanceof StreamEndEvent) {
                $finishReason = $event->finishReason;
                $usage = $event->usage;
                $additionalContent = $event->additionalContent;
                $this->handleStreamEnd($accumulatedText,
                    $toolCalls,
                    $toolResults,
                    $providerToolCalls,
                    $messages,
                    $finishReason,
                    $usage,
                    $additionalContent
                );
            }
        }
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  ProviderToolCall[]  $providerToolCalls
     * @param  Message[]  $messages
     */
    protected function handleTextStart(
        string &$accumulatedText,
        array &$toolCalls,
        array &$toolResults,
        array &$providerToolCalls,
        array &$messages
    ): void {
        $this->finalizeCurrentMessage($accumulatedText, $toolCalls, $toolResults, $providerToolCalls, $messages);
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  ProviderToolCall[]  $providerToolCalls
     * @param  Message[]  $messages
     * @param  array<string,mixed>  $additionalContent
     */
    protected function handleStreamEnd(
        string &$accumulatedText,
        array &$toolCalls,
        array &$toolResults,
        array &$providerToolCalls,
        array &$messages,
        FinishReason $finishReason,
        ?Usage $usage,
        array $additionalContent
    ): void {
        $this->finalizeCurrentMessage($accumulatedText, $toolCalls, $toolResults, $providerToolCalls, $messages);

        if ($this->onCompleteCallback instanceof Closure) {
            $messagesCollection = new Collection($messages);

            // Create a step with the collected data
            $steps = new Collection;
            if ($accumulatedText !== '' || $toolCalls !== [] || $toolResults !== [] || $providerToolCalls !== []) {
                $steps->push(new Step(
                    text: $messagesCollection
                        ->filter(fn (Message $msg): bool => $msg instanceof AssistantMessage)
                        ->map(fn (Message $msg): string => $msg instanceof AssistantMessage ? $msg->content : '')
                        ->join(''),
                    finishReason: $finishReason,
                    toolCalls: $messagesCollection
                        ->filter(fn (Message $msg): bool => $msg instanceof AssistantMessage)
                        ->flatMap(fn (Message $msg): array => $msg instanceof AssistantMessage ? $msg->toolCalls : [])
                        ->all(),
                    toolResults: $messagesCollection
                        ->filter(fn (Message $msg): bool => $msg instanceof ToolResultMessage)
                        ->flatMap(fn (Message $msg): array => $msg instanceof ToolResultMessage ? $msg->toolResults : [])
                        ->all(),
                    providerToolCalls: $providerToolCalls,
                    usage: $usage ?? new Usage(0, 0),
                    meta: new Meta(id: '', model: '', rateLimits: []),
                    messages: $messages,
                    systemPrompts: [],
                    additionalContent: $additionalContent
                ));
            }

            $response = new Response(
                steps: $steps,
                text: $messagesCollection
                    ->filter(fn (Message $msg): bool => $msg instanceof AssistantMessage)
                    ->map(fn (Message $msg): string => $msg instanceof AssistantMessage ? $msg->content : '')
                    ->join(''),
                finishReason: $finishReason,
                toolCalls: $messagesCollection
                    ->filter(fn (Message $msg): bool => $msg instanceof AssistantMessage)
                    ->flatMap(fn (Message $msg): array => $msg instanceof AssistantMessage ? $msg->toolCalls : [])
                    ->all(),
                toolResults: $messagesCollection
                    ->filter(fn (Message $msg): bool => $msg instanceof ToolResultMessage)
                    ->flatMap(fn (Message $msg): array => $msg instanceof ToolResultMessage ? $msg->toolResults : [])
                    ->all(),
                usage: $usage ?? new Usage(0, 0),
                meta: new Meta(id: '', model: '', rateLimits: []),
                messages: $messagesCollection,
                additionalContent: $additionalContent
            );

            ($this->onCompleteCallback)($this->pendingRequest, $messagesCollection, $response);
        }
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  ProviderToolCall[]  $providerToolCalls
     * @param  Message[]  $messages
     */
    protected function finalizeCurrentMessage(
        string &$accumulatedText,
        array &$toolCalls,
        array &$toolResults,
        array &$providerToolCalls,
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

        // Note: Provider tool calls are not added to messages,
        // they are tracked separately and included in steps
    }
}
