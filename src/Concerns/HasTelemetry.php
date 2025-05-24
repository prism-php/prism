<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Generator;
use Prism\Prism\Contracts\Telemetry;

trait HasTelemetry
{
    protected function telemetry(): Telemetry
    {
        return app(Telemetry::class);
    }

    /**
     * @template T
     *
     * @param  non-empty-string  $spanName
     * @param  array<non-empty-string, mixed>  $attributes
     * @param  callable(): T  $callback
     * @return T
     */
    protected function trace(string $spanName, array $attributes, callable $callback): mixed
    {
        return $this->telemetry()->span($spanName, $attributes, $callback);
    }

    /**
     * @template T
     *
     * @param  non-empty-string  $spanName
     * @param  array<non-empty-string, mixed>  $attributes
     * @param  callable(): Generator<T>  $callback
     * @return Generator<T>
     */
    protected function traceStream(string $spanName, array $attributes, callable $callback): Generator
    {
        $telemetry = $this->telemetry();

        if (! $telemetry->enabled()) {
            yield from $callback();

            return;
        }

        // For streaming, we need to handle chunk counting specially
        $chunkCount = 0;

        yield from $telemetry->span($spanName, $attributes, function () use ($callback, &$chunkCount): Generator {
            foreach ($callback() as $chunk) {
                $chunkCount++;
                yield $chunk;
            }
        });
    }
}
