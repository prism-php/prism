<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Generator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

trait HasTelemetry
{
    protected function shouldTrace(): bool
    {
        return config('prism.telemetry.enabled', false);
    }

    protected function getTracer(): ?TracerInterface
    {
        if (! $this->shouldTrace()) {
            return null;
        }

        return app(TracerInterface::class);
    }

    /**
     * @template T
     *
     * @param  non-empty-string  $spanName
     * @param  callable(SpanInterface|null): T  $callback
     * @param  array<non-empty-string, mixed>  $attributes
     * @return T
     */
    protected function trace(string $spanName, callable $callback, array $attributes = []): mixed
    {
        $tracer = $this->getTracer();

        if (! $tracer) {
            return $callback(null);
        }

        $span = $tracer->spanBuilder($spanName)->startSpan();

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        $scope = $span->activate();

        try {
            $result = $callback($span);
            $span->setStatus(StatusCode::STATUS_OK);

            return $result;
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * @template T
     *
     * @param  non-empty-string  $spanName
     * @param  callable(SpanInterface|null): Generator<T>  $callback
     * @param  array<non-empty-string, mixed>  $attributes
     * @return Generator<T>
     */
    protected function traceStream(string $spanName, callable $callback, array $attributes = []): Generator
    {
        $tracer = $this->getTracer();

        if (! $tracer) {
            yield from $callback(null);

            return;
        }

        $span = $tracer->spanBuilder($spanName)->startSpan();
        $chunkCount = 0;

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        $scope = $span->activate();

        try {
            foreach ($callback($span) as $chunk) {
                $chunkCount++;
                yield $chunk;
            }

            $span->setAttribute('prism.stream.chunk_count', $chunkCount);
            $span->setStatus(StatusCode::STATUS_OK);
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * @param  array<non-empty-string, mixed>  $attributes
     */
    protected function addSpanAttributes(?SpanInterface $span, array $attributes): void
    {
        if (!$span instanceof \OpenTelemetry\API\Trace\SpanInterface) {
            return;
        }

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }
    }
}
