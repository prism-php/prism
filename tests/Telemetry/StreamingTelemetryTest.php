<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Events\StreamingCompleted;
use Prism\Prism\Telemetry\Events\StreamingStarted;
use Prism\Prism\Telemetry\Listeners\TelemetryEventListener;
use Prism\Prism\Telemetry\SpanCollector;
use Prism\Prism\Telemetry\SpanData;
use Prism\Prism\Text\Request;

beforeEach(function (): void {
    config(['prism.telemetry.enabled' => true]);
    Event::fake();
    Context::forgetHidden('prism.telemetry.trace_id');
    Context::forgetHidden('prism.telemetry.current_span_id');
    Context::forgetHidden('prism.telemetry.metadata');
});

describe('event dispatch', function (): void {
    it('creates StreamingStarted event with correct properties', function (): void {
        $request = createStreamingRequest();
        $event = new StreamingStarted(
            spanId: 'stream-span-123',
            traceId: 'stream-trace-123',
            parentSpanId: null,
            request: $request,
        );

        expect($event->spanId)->toBe('stream-span-123')
            ->and($event->traceId)->toBe('stream-trace-123')
            ->and($event->request)->toBe($request)
            ->and($event->streamStart)->toBeNull();
    });

    it('creates StreamingCompleted event with correct properties', function (): void {
        $request = createStreamingRequest();
        $event = new StreamingCompleted(
            spanId: 'stream-span-123',
            traceId: 'stream-trace-123',
            parentSpanId: null,
            request: $request,
        );

        expect($event->spanId)->toBe('stream-span-123')
            ->and($event->request)->toBe($request)
            ->and($event->streamEnd)->toBeNull();
    });
});

describe('SpanCollector', function (): void {
    it('extracts streaming attributes and sets correct operation', function (): void {
        $capturedSpan = captureSpanFromCollector();

        expect($capturedSpan)->toBeInstanceOf(SpanData::class)
            ->and($capturedSpan->operation)->toBe('streaming')
            ->and($capturedSpan->attributes)->toHaveKey('model')
            ->and($capturedSpan->attributes['model'])->toBe('gpt-4');
    });

    it('maintains trace context from events', function (): void {
        $capturedSpan = captureSpanFromCollector(
            spanId: 'child-stream-span',
            traceId: 'parent-trace-id',
            parentSpanId: 'parent-span-id'
        );

        expect($capturedSpan->traceId)->toBe('parent-trace-id')
            ->and($capturedSpan->parentSpanId)->toBe('parent-span-id');
    });

    it('preserves telemetry context metadata', function (): void {
        Context::addHidden('prism.telemetry.metadata', [
            'user_id' => 'stream-user-123',
            'session_id' => 'stream-session-456',
        ]);

        $capturedSpan = captureSpanFromCollector();

        expect($capturedSpan->attributes)->toHaveKey('metadata')
            ->and($capturedSpan->attributes['metadata']['user_id'])->toBe('stream-user-123')
            ->and($capturedSpan->attributes['metadata']['session_id'])->toBe('stream-session-456');

        Context::forgetHidden('prism.telemetry.metadata');
    });
});

describe('TelemetryEventListener', function (): void {
    it('routes streaming events through collector', function (): void {
        $capturedSpan = null;
        $driver = createStreamingMockDriver($capturedSpan);
        $collector = new SpanCollector($driver);
        $listener = new TelemetryEventListener($collector);

        $request = createStreamingRequest();

        $listener->handleStreamingStarted(new StreamingStarted(
            spanId: 'stream-span-123',
            traceId: 'stream-trace-123',
            parentSpanId: null,
            request: $request,
        ));

        $listener->handleStreamingCompleted(new StreamingCompleted(
            spanId: 'stream-span-123',
            traceId: 'stream-trace-123',
            parentSpanId: null,
            request: $request,
        ));

        expect($capturedSpan)->toBeInstanceOf(SpanData::class)
            ->and($capturedSpan->operation)->toBe('streaming');
    });
});

function createStreamingRequest(): Request
{
    return new Request(
        model: 'gpt-4',
        providerKey: 'openai',
        systemPrompts: [],
        prompt: 'Stream this response',
        messages: [],
        maxSteps: 1,
        maxTokens: 200,
        temperature: 0.5,
        topP: 1.0,
        tools: [],
        clientOptions: [],
        clientRetry: [3],
        toolChoice: null,
    );
}

function createStreamingMockDriver(?SpanData &$capturedSpan): TelemetryDriver
{
    $driver = Mockery::mock(TelemetryDriver::class);
    $driver->shouldReceive('recordSpan')
        ->once()
        ->with(Mockery::on(function ($span) use (&$capturedSpan): bool {
            $capturedSpan = $span;

            return $span instanceof SpanData;
        }));

    return $driver;
}

function captureSpanFromCollector(
    string $spanId = 'stream-span-123',
    string $traceId = 'stream-trace-123',
    ?string $parentSpanId = null
): SpanData {
    $capturedSpan = null;
    $driver = createStreamingMockDriver($capturedSpan);
    $collector = new SpanCollector($driver);
    $request = createStreamingRequest();

    $collector->startSpan(new StreamingStarted(
        spanId: $spanId,
        traceId: $traceId,
        parentSpanId: $parentSpanId,
        request: $request,
    ));

    $collector->endSpan(new StreamingCompleted(
        spanId: $spanId,
        traceId: $traceId,
        parentSpanId: $parentSpanId,
        request: $request,
    ));

    return $capturedSpan;
}
