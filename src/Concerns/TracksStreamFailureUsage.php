<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Closure;
use Generator;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\StreamFailureUsageTracker;
use Prism\Prism\ValueObjects\StreamFailureUsage;
use Throwable;

trait TracksStreamFailureUsage
{
    protected ?StreamFailureUsageTracker $streamFailureTracker = null;

    protected function streamFailureUsage(): ?StreamFailureUsage
    {
        return $this->streamFailureTracker?->snapshot();
    }

    /**
     * The catch surrounds the native body read/decoder only. Consumer, listener
     * and tool code runs outside it and keeps its own exception semantics.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return Generator<int, ErrorEvent, mixed, T>
     */
    protected function readStreamValue(Closure $read): Generator
    {
        try {
            $data = $read();
        } catch (Throwable $exception) {
            if ($exception instanceof PrismException) {
                $exception->withStreamFailureUsage($this->streamFailureUsage());
            } elseif ($this->streamFailureUsage() !== null) {
                yield new ErrorEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    errorType: 'stream_read_failed',
                    message: 'The provider stream could not be read.',
                    recoverable: false,
                    failureUsage: $this->streamFailureUsage(),
                );
            }

            throw $exception;
        }

        if (is_array($data)) {
            $this->streamFailureTracker?->record($data);
        }

        return $data;
    }
}
