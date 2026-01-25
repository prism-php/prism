<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Generator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Events\SpanException;

trait EmitsTelemetry
{
    /**
     * Execute a callable with telemetry tracking.
     *
     * @template TResponse
     *
     * @param  callable(string, string, ?string): object  $startEventFactory  Factory to create start event (spanId, traceId, parentSpanId) => event
     * @param  callable(string, string, ?string, TResponse): object  $endEventFactory  Factory to create end event (spanId, traceId, parentSpanId, response) => event
     * @param  callable(): TResponse  $execute  The operation to execute
     * @return TResponse
     */
    protected function withTelemetry(
        callable $startEventFactory,
        callable $endEventFactory,
        callable $execute,
    ): mixed {
        if (! config('prism.telemetry.enabled', false)) {
            return $execute();
        }

        // Push telemetry context if HasTelemetryContext trait is used
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists($this, 'pushTelemetryContext')) {
            $this->pushTelemetryContext();
        }

        $spanId = bin2hex(random_bytes(8));
        $traceId = Context::getHidden('prism.telemetry.trace_id') ?? bin2hex(random_bytes(16));
        $parentSpanId = Context::getHidden('prism.telemetry.current_span_id');

        // Use hidden context to avoid leaking telemetry IDs into logs
        Context::addHidden('prism.telemetry.trace_id', $traceId);
        Context::addHidden('prism.telemetry.current_span_id', $spanId);

        Event::dispatch($startEventFactory($spanId, $traceId, $parentSpanId));

        try {
            $response = $execute();

            Event::dispatch($endEventFactory($spanId, $traceId, $parentSpanId, $response));

            return $response;
        } catch (\Throwable $e) {
            Event::dispatch(new SpanException($spanId, $e));

            throw $e;
        } finally {
            Context::addHidden('prism.telemetry.current_span_id', $parentSpanId);

            // Shutdown driver to flush any buffered spans
            app(TelemetryDriver::class)->shutdown();
        }
    }

    /**
     * Execute a generator with telemetry tracking.
     *
     * If event types are provided, dispatches telemetry when those events are encountered.
     * Otherwise, dispatches before/after the entire generator execution.
     *
     * @template TYield
     *
     * @param  callable(string, string, ?string, mixed): object  $startEventFactory  Factory to create start event
     * @param  callable(string, string, ?string, mixed): object  $endEventFactory  Factory to create end event
     * @param  callable(): Generator<TYield>  $execute  The generator to iterate
     * @param  class-string|null  $startEventType  Stream event type that triggers start telemetry (null = dispatch immediately)
     * @param  class-string|null  $endEventType  Stream event type that triggers end telemetry (null = dispatch after generator completes)
     * @return Generator<TYield>
     */
    protected function withStreamingTelemetry(
        callable $startEventFactory,
        callable $endEventFactory,
        callable $execute,
        ?string $startEventType = null,
        ?string $endEventType = null,
    ): Generator {
        if (! config('prism.telemetry.enabled', false)) {
            yield from $execute();

            return;
        }

        // Push telemetry context if HasTelemetryContext trait is used
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists($this, 'pushTelemetryContext')) {
            $this->pushTelemetryContext();
        }

        $spanId = bin2hex(random_bytes(8));
        $traceId = Context::getHidden('prism.telemetry.trace_id') ?? bin2hex(random_bytes(16));
        $parentSpanId = Context::getHidden('prism.telemetry.current_span_id');

        // Use hidden context to avoid leaking telemetry IDs into logs
        Context::addHidden('prism.telemetry.trace_id', $traceId);
        Context::addHidden('prism.telemetry.current_span_id', $spanId);

        // Dispatch start before generator if no event type specified
        if ($startEventType === null) {
            Event::dispatch($startEventFactory($spanId, $traceId, $parentSpanId, null));
        }

        try {
            foreach ($execute() as $event) {
                if ($startEventType !== null && $event instanceof $startEventType) {
                    Event::dispatch($startEventFactory($spanId, $traceId, $parentSpanId, $event));
                }

                if ($endEventType !== null && $event instanceof $endEventType) {
                    Event::dispatch($endEventFactory($spanId, $traceId, $parentSpanId, $event));
                }

                yield $event;
            }

            // Dispatch end after generator if no event type specified
            if ($endEventType === null) {
                Event::dispatch($endEventFactory($spanId, $traceId, $parentSpanId, null));
            }
        } catch (\Throwable $e) {
            Event::dispatch(new SpanException($spanId, $e));

            throw $e;
        } finally {
            Context::addHidden('prism.telemetry.current_span_id', $parentSpanId);

            // Shutdown driver to flush any buffered spans
            app(TelemetryDriver::class)->shutdown();
        }
    }
}
