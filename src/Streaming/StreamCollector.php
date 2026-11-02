<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderToolCall;
use Prism\Prism\ValueObjects\Usage;

/**
 * Passes a stream's events through untouched and, at its end, hands the
 * messages and a Response built from them to a callback.
 *
 * Upstream removed this class in #1043 without a deprecation, and the fork's
 * v0.132.0 shipped that removal undeclared: every consumer built against it
 * -- prism-harness among them -- failed with "Class not found" after a
 * routine `composer update`. It is restored with the same constructor,
 * `collect()` and callback signature, and now builds its messages with
 * {@see StreamedMessages}, so it follows the post-#1043 stream shape (one
 * StepFinishEvent per step, exactly one StreamEndEvent) and keeps each
 * step's replay metadata.
 *
 * @deprecated Use {@see StreamedMessages::fromEvents()} for the messages, and
 *             the StreamEndEvent for the finish reason and usage. Kept until a
 *             major release says otherwise.
 */
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
        /** @var StreamEvent[] $events */
        $events = [];

        foreach ($this->stream as $event) {
            yield $event;

            $events[] = $event;

            if ($event instanceof StreamEndEvent) {
                $this->complete($events, $event);
            }
        }
    }

    /**
     * @param  StreamEvent[]  $events
     */
    protected function complete(array $events, StreamEndEvent $end): void
    {
        if (! $this->onCompleteCallback instanceof Closure) {
            return;
        }

        $messages = StreamedMessages::fromEvents($events);

        $providerToolCalls = array_values(array_map(
            fn (ProviderToolEvent $event): ProviderToolCall => new ProviderToolCall(
                id: $event->itemId,
                type: $event->toolType,
                status: $event->status,
                data: $event->data,
            ),
            array_filter($events, fn (StreamEvent $event): bool => $event instanceof ProviderToolEvent),
        ));

        $assistant = $messages->filter(fn (Message $message): bool => $message instanceof AssistantMessage);
        $text = $assistant->map(fn (Message $message): string => $message instanceof AssistantMessage ? $message->content : '')->join('');
        $toolCalls = $assistant->flatMap(fn (Message $message): array => $message instanceof AssistantMessage ? $message->toolCalls : [])->values()->all();
        $toolResults = $messages
            ->filter(fn (Message $message): bool => $message instanceof ToolResultMessage)
            ->flatMap(fn (Message $message): array => $message instanceof ToolResultMessage ? $message->toolResults : [])
            ->values()
            ->all();
        $finishReason = $end->finishReason;
        $usage = $end->usage ?? $this->stepUsage($events);
        $meta = new Meta(id: '', model: '', rateLimits: []);

        $steps = new Collection;

        if ($text !== '' || $toolCalls !== [] || $toolResults !== [] || $providerToolCalls !== []) {
            $steps->push(new Step(
                text: $text,
                finishReason: $finishReason,
                toolCalls: $toolCalls,
                toolResults: $toolResults,
                providerToolCalls: $providerToolCalls,
                usage: $usage,
                meta: $meta,
                messages: $messages->all(),
                systemPrompts: [],
                additionalContent: $end->additionalContent,
            ));
        }

        $response = new Response(
            steps: $steps,
            text: $text,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            usage: $usage,
            meta: $meta,
            messages: $messages,
            additionalContent: $end->additionalContent,
        );

        ($this->onCompleteCallback)($this->pendingRequest, $messages, $response);
    }

    /**
     * A stream end without usage means the total is unknown (an interrupted
     * stream, for one). The response's usage is not nullable, so it carries
     * what the steps did report, never invented zeros; its finish reason says
     * whether that total is complete.
     *
     * @param  StreamEvent[]  $events
     */
    protected function stepUsage(array $events): Usage
    {
        $total = new Usage(0, 0);

        foreach ($events as $event) {
            if ($event instanceof StepFinishEvent && $event->usage instanceof Usage) {
                $total = new Usage(
                    promptTokens: $total->promptTokens + $event->usage->promptTokens,
                    completionTokens: $total->completionTokens + $event->usage->completionTokens,
                    cacheWriteInputTokens: $this->sum($total->cacheWriteInputTokens, $event->usage->cacheWriteInputTokens),
                    cacheReadInputTokens: $this->sum($total->cacheReadInputTokens, $event->usage->cacheReadInputTokens),
                    thoughtTokens: $this->sum($total->thoughtTokens, $event->usage->thoughtTokens),
                    cacheWrite5mInputTokens: $this->sumCacheWrites($total->cacheWriteInputTokens, $total->cacheWrite5mInputTokens, $event->usage->cacheWriteInputTokens, $event->usage->cacheWrite5mInputTokens),
                    cacheWrite1hInputTokens: $this->sumCacheWrites($total->cacheWriteInputTokens, $total->cacheWrite1hInputTokens, $event->usage->cacheWriteInputTokens, $event->usage->cacheWrite1hInputTokens),
                );
            }
        }

        return $total;
    }

    protected function sum(?int $total, ?int $tokens): ?int
    {
        return $total === null && $tokens === null ? null : ($total ?? 0) + ($tokens ?? 0);
    }

    protected function sumCacheWrites(?int $previousTotal, ?int $previous, ?int $nextTotal, ?int $next): ?int
    {
        if (($previousTotal === null && $nextTotal === null)
            || (($previousTotal ?? 0) !== 0 && $previous === null)
            || (($nextTotal ?? 0) !== 0 && $next === null)) {
            return null;
        }

        return ($previous ?? 0) + ($next ?? 0);
    }
}
