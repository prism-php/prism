<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Adapters;

use Generator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SSEAdapter
{
    public function __invoke(Generator $events): StreamedResponse
    {
        return response()->stream(function () use ($events): void {
            foreach ($events as $event) {
                if (connection_aborted() !== 0) {
                    break;
                }

                echo vsprintf("event: %s\ndata: %s\n\n", [
                    $event->type()->value,
                    json_encode($event->toArray()),
                ]);

                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}
