<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Adapters;

use Generator;
use Illuminate\Support\Collection;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Text\PendingRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SSEAdapter
{
    /**
     * @param  callable(PendingRequest, Collection<int, StreamEvent>): void|null  $callback
     */
    public function __invoke(Generator $events, ?PendingRequest $pendingRequest = null, ?callable $callback = null): StreamedResponse
    {
        return response()->stream(function () use ($events, $pendingRequest, $callback): void {
            /** @var Collection<int, StreamEvent> $collectedEvents */
            $collectedEvents = new Collection;

            foreach ($events as $event) {
                $collectedEvents->push($event);

                if (connection_aborted() !== 0) {
                    break;
                }

                echo vsprintf("event: %s\ndata: %s\n\n", [
                    $event->type()->value,
                    json_encode($event->toArray()),
                ]);

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            if ($callback !== null && $pendingRequest instanceof PendingRequest) {
                $callback($pendingRequest, $collectedEvents);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}
