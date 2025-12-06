<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Adapters;

use Generator;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Prism\Prism\Events\Broadcasting\ErrorBroadcast;
use Prism\Prism\Events\Broadcasting\ProviderToolEventBroadcast;
use Prism\Prism\Events\Broadcasting\StreamEndBroadcast;
use Prism\Prism\Events\Broadcasting\StreamStartBroadcast;
use Prism\Prism\Events\Broadcasting\TextCompleteBroadcast;
use Prism\Prism\Events\Broadcasting\TextDeltaBroadcast;
use Prism\Prism\Events\Broadcasting\TextStartBroadcast;
use Prism\Prism\Events\Broadcasting\ThinkingBroadcast;
use Prism\Prism\Events\Broadcasting\ThinkingCompleteBroadcast;
use Prism\Prism\Events\Broadcasting\ThinkingStartBroadcast;
use Prism\Prism\Events\Broadcasting\ToolCallBroadcast;
use Prism\Prism\Events\Broadcasting\ToolResultBroadcast;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
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
use Prism\Prism\Text\PendingRequest;

class BroadcastAdapter
{
    /**
     * @param  Channel|Channel[]  $channels
     */
    public function __construct(
        protected Channel|array $channels
    ) {}

    /**
     * @param  callable(PendingRequest, Collection<int, StreamEvent>): void|null  $callback
     */
    public function __invoke(Generator $events, ?PendingRequest $pendingRequest = null, ?callable $callback = null): void
    {
        /** @var Collection<int, StreamEvent> $collectedEvents */
        $collectedEvents = new Collection;

        foreach ($events as $event) {
            $collectedEvents->push($event);
            event($this->broadcastEvent($event));
        }

        if ($callback !== null && $pendingRequest instanceof \Prism\Prism\Text\PendingRequest) {
            $callback($pendingRequest, $collectedEvents);
        }
    }

    protected function broadcastEvent(StreamEvent $event): ShouldBroadcast
    {
        return match ($event::class) {
            StreamStartEvent::class => new StreamStartBroadcast($event, $this->channels),
            TextStartEvent::class => new TextStartBroadcast($event, $this->channels),
            TextDeltaEvent::class => new TextDeltaBroadcast($event, $this->channels),
            TextCompleteEvent::class => new TextCompleteBroadcast($event, $this->channels),
            ThinkingStartEvent::class => new ThinkingStartBroadcast($event, $this->channels),
            ThinkingEvent::class => new ThinkingBroadcast($event, $this->channels),
            ThinkingCompleteEvent::class => new ThinkingCompleteBroadcast($event, $this->channels),
            ToolCallEvent::class => new ToolCallBroadcast($event, $this->channels),
            ToolResultEvent::class => new ToolResultBroadcast($event, $this->channels),
            ProviderToolEvent::class => new ProviderToolEventBroadcast($event, $this->channels),
            ErrorEvent::class => new ErrorBroadcast($event, $this->channels),
            StreamEndEvent::class => new StreamEndBroadcast($event, $this->channels),
            default => throw new InvalidArgumentException('Unsupported event type for broadcasting: '.$event::class),
        };
    }
}
