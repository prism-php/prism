<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Adapters;

use Generator;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Prism\Prism\Events\Broadcasting\ArtifactBroadcast;
use Prism\Prism\Events\Broadcasting\ErrorBroadcast;
use Prism\Prism\Events\Broadcasting\ProviderToolEventBroadcast;
use Prism\Prism\Events\Broadcasting\StepFinishBroadcast;
use Prism\Prism\Events\Broadcasting\StepStartBroadcast;
use Prism\Prism\Events\Broadcasting\StreamEndBroadcast;
use Prism\Prism\Events\Broadcasting\StreamStartBroadcast;
use Prism\Prism\Events\Broadcasting\TextCompleteBroadcast;
use Prism\Prism\Events\Broadcasting\TextDeltaBroadcast;
use Prism\Prism\Events\Broadcasting\TextStartBroadcast;
use Prism\Prism\Events\Broadcasting\ThinkingBroadcast;
use Prism\Prism\Events\Broadcasting\ThinkingCompleteBroadcast;
use Prism\Prism\Events\Broadcasting\ThinkingStartBroadcast;
use Prism\Prism\Events\Broadcasting\ToolCallBroadcast;
use Prism\Prism\Events\Broadcasting\ToolCallDeltaBroadcast;
use Prism\Prism\Events\Broadcasting\ToolResultBroadcast;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
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

        if ($callback !== null && $pendingRequest instanceof PendingRequest) {
            $callback($pendingRequest, $collectedEvents);
        }
    }

    protected function broadcastEvent(StreamEvent $event): ShouldBroadcast
    {
        return match ($event::class) {
            StreamStartEvent::class => resolve(StreamStartBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            StepStartEvent::class => resolve(StepStartBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            TextStartEvent::class => resolve(TextStartBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            TextDeltaEvent::class => resolve(TextDeltaBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            TextCompleteEvent::class => resolve(TextCompleteBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            ThinkingStartEvent::class => resolve(ThinkingStartBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            ThinkingEvent::class => resolve(ThinkingBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            ThinkingCompleteEvent::class => resolve(ThinkingCompleteBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            ToolCallEvent::class => resolve(ToolCallBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            ToolCallDeltaEvent::class => resolve(ToolCallDeltaBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            ToolResultEvent::class => resolve(ToolResultBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            ArtifactEvent::class => resolve(ArtifactBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            ProviderToolEvent::class => resolve(ProviderToolEventBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            ErrorEvent::class => resolve(ErrorBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            StepFinishEvent::class => resolve(StepFinishBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            StreamEndEvent::class => resolve(StreamEndBroadcast::class, ['event' => $event, 'channels' => $this->channels]),
            default => throw new InvalidArgumentException('Unsupported event type for broadcasting: '.$event::class),
        };
    }
}
