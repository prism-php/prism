<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Adapters\Concerns;

use Generator;
use Illuminate\Support\Collection;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;

trait HandlesClientDisconnects
{
    protected function connectionAborted(): bool
    {
        return connection_aborted() !== 0;
    }

    /**
     * When the client disconnects after a step's tools have run, the rest of
     * that step's events (its remaining tool results and its StepFinishEvent,
     * which carries the replay data) are already computed. Collect them
     * without output so the step can be stored, and stop there: advancing
     * past the StepFinishEvent sends the next provider request.
     *
     * @param  Collection<int, StreamEvent>  $collectedEvents
     */
    protected function collectRanToolStep(Generator $events, Collection $collectedEvents): void
    {
        if (! $this->toolsRanInOpenStep($collectedEvents)) {
            return;
        }

        do {
            $events->next();

            if (! $events->valid()) {
                return;
            }

            /** @var StreamEvent $event */
            $event = $events->current();
            $collectedEvents->push($event);
        } while ($this->isToolOutput($event));
    }

    /**
     * Tool output is only yielded once every tool in the step has run, and is
     * followed directly by the step's StepFinishEvent.
     *
     * @param  Collection<int, StreamEvent>  $collectedEvents
     */
    protected function toolsRanInOpenStep(Collection $collectedEvents): bool
    {
        if (! $this->isToolOutput($collectedEvents->last())) {
            return false;
        }

        $lastStepBoundary = $collectedEvents->last(
            fn (StreamEvent $event): bool => $event instanceof StepStartEvent || $event instanceof StepFinishEvent
        );

        return $lastStepBoundary instanceof StepStartEvent;
    }

    protected function isToolOutput(?StreamEvent $event): bool
    {
        return $event instanceof ToolResultEvent || $event instanceof ArtifactEvent;
    }
}
