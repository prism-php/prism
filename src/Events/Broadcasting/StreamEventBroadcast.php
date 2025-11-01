<?php

declare(strict_types=1);

namespace Prism\Prism\Events\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Prism\Prism\Streaming\Events\StreamEvent;

abstract class StreamEventBroadcast implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    /**
     * @param  Channel|Channel[]  $channels
     */
    public function __construct(
        public StreamEvent $event,
        public Channel|array $channels
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return is_array($this->channels) ? $this->channels : [$this->channels];
    }

    public function broadcastAs(): string
    {
        return $this->event->eventKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->event->toArray();
    }
}
