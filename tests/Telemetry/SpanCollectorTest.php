<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Telemetry\Events\SpanException;
use Prism\Prism\Telemetry\Events\TextGenerationCompleted;
use Prism\Prism\Telemetry\Events\TextGenerationStarted;
use Prism\Prism\Telemetry\SpanCollector;
use Prism\Prism\Telemetry\SpanData;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

describe('span lifecycle', function (): void {
    it('returns span id when starting a span', function (): void {
        $driver = Mockery::mock(TelemetryDriver::class);
        $driver->shouldReceive('recordSpan')->never();

        $collector = new SpanCollector($driver);
        $spanId = $collector->startSpan(createCollectorMockStartEvent('my-span-123'));

        expect($spanId)->toBe('my-span-123');
    });

    it('sends span data to driver when span ends', function (): void {
        $capturedSpan = captureSpan();

        expect($capturedSpan)->toBeInstanceOf(SpanData::class)
            ->and($capturedSpan->spanId)->toBe('test-span-id')
            ->and($capturedSpan->operation)->toBe('text_generation')
            ->and($capturedSpan->attributes)->toBeArray()
            ->and($capturedSpan->hasError())->toBeFalse();
    });

    it('calculates span duration correctly', function (): void {
        $capturedSpan = null;
        $driver = mockDriver($capturedSpan);
        $collector = new SpanCollector($driver);

        $collector->startSpan(createCollectorMockStartEvent('test-span-id'));
        usleep(1000); // 1ms delay
        $collector->endSpan(createCollectorMockEndEvent('test-span-id'));

        expect($capturedSpan->durationNano())->toBeGreaterThan(0)
            ->and($capturedSpan->startTimeNano)->toBeLessThan($capturedSpan->endTimeNano);
    });
});

describe('attribute extraction', function (): void {
    it('extracts model attributes from start event', function (): void {
        $capturedSpan = captureSpan();

        expect($capturedSpan->attributes['model'])->toBe('gpt-4')
            ->and($capturedSpan->attributes['provider'])->toBe('openai')
            ->and($capturedSpan->attributes['temperature'])->toBe(0.7)
            ->and($capturedSpan->attributes['max_tokens'])->toBe(100);
    });

    it('extracts token usage from end event', function (): void {
        $capturedSpan = captureSpan();

        expect($capturedSpan->attributes['usage'])->toBeArray()
            ->and($capturedSpan->attributes['usage']['prompt_tokens'])->toBe(10)
            ->and($capturedSpan->attributes['usage']['completion_tokens'])->toBe(5);
    });

    it('extracts output and messages from end event', function (): void {
        $capturedSpan = captureSpan();

        expect($capturedSpan->attributes['output'])->toBe('Hello there!')
            ->and($capturedSpan->attributes['messages'])->toBeArray();
    });

    it('includes operation in span data', function (): void {
        $capturedSpan = captureSpan();

        expect($capturedSpan->operation)->toBe('text_generation');
    });
});

describe('trace context', function (): void {
    it('uses trace id from event', function (): void {
        $capturedSpan = captureSpan('test-span-id', 'custom-trace-id-from-event');

        expect($capturedSpan->traceId)->toBe('custom-trace-id-from-event');
    });

    it('uses parent span id from event', function (): void {
        $capturedSpan = captureSpan('child-span', 'trace-123', 'parent-span-123');

        expect($capturedSpan->parentSpanId)->toBe('parent-span-123');
    });

    it('adds context metadata from Laravel hidden Context', function (): void {
        Context::addHidden('prism.telemetry.metadata', ['user_id' => '123', 'session' => 'abc']);

        $capturedSpan = captureSpan('test-span-id', 'trace-id');

        expect($capturedSpan->attributes['metadata']['user_id'])->toBe('123')
            ->and($capturedSpan->attributes['metadata']['session'])->toBe('abc');

        Context::forgetHidden('prism.telemetry.metadata');
    });
});

describe('span events', function (): void {
    it('adds events to pending spans', function (): void {
        $capturedSpan = null;
        $driver = mockDriver($capturedSpan);
        $collector = new SpanCollector($driver);

        $collector->startSpan(createCollectorMockStartEvent('test-span-id'));
        $collector->addEvent('test-span-id', 'token_generated', now_nanos(), ['token_count' => 10]);
        $collector->addEvent('test-span-id', 'chunk_received', now_nanos());
        $collector->endSpan(createCollectorMockEndEvent('test-span-id'));

        expect($capturedSpan->events)->toHaveCount(2)
            ->and($capturedSpan->events[0]['name'])->toBe('token_generated')
            ->and($capturedSpan->events[0]['attributes'])->toBe(['token_count' => 10])
            ->and($capturedSpan->events[1]['name'])->toBe('chunk_received');
    });

    it('ignores events for unknown span id', function (): void {
        $driver = Mockery::mock(TelemetryDriver::class)->shouldIgnoreMissing();
        $collector = new SpanCollector($driver);

        $collector->addEvent('unknown-span-id', 'event', now_nanos(), ['key' => 'value']);

        expect(true)->toBeTrue();
    });
});

describe('exception handling', function (): void {
    it('records exceptions on spans', function (): void {
        $capturedSpan = null;
        $driver = mockDriver($capturedSpan);
        $collector = new SpanCollector($driver);
        $exception = new Exception('Test error');

        $collector->startSpan(createCollectorMockStartEvent('test-span-id'));
        $collector->recordException(new SpanException('test-span-id', $exception));
        $collector->endSpan(createCollectorMockEndEvent('test-span-id'));

        expect($capturedSpan->hasError())->toBeTrue()
            ->and($capturedSpan->exception)->toBe($exception)
            ->and($capturedSpan->events)->toHaveCount(1)
            ->and($capturedSpan->events[0]['name'])->toBe('exception')
            ->and($capturedSpan->events[0]['attributes']['type'])->toBe(Exception::class);
    });

    it('ignores exceptions for unknown span id', function (): void {
        $driver = Mockery::mock(TelemetryDriver::class)->shouldIgnoreMissing();
        $collector = new SpanCollector($driver);

        $collector->recordException(new SpanException('unknown-span-id', new Exception('Test error')));

        expect(true)->toBeTrue();
    });
});

describe('edge cases', function (): void {
    it('does not dispatch for unknown span id', function (): void {
        $driver = Mockery::mock(TelemetryDriver::class);
        $driver->shouldReceive('recordSpan')->never();

        $collector = new SpanCollector($driver);
        $collector->endSpan(createCollectorMockEndEvent('unknown-span-id'));

        expect(true)->toBeTrue();
    });

    it('tracks multiple spans with different ids', function (): void {
        $capturedSpans = [];
        $driver = Mockery::mock(TelemetryDriver::class);
        $driver->shouldReceive('recordSpan')
            ->twice()
            ->with(Mockery::on(function ($span) use (&$capturedSpans): bool {
                $capturedSpans[] = $span;

                return $span instanceof SpanData;
            }));

        $collector = new SpanCollector($driver);
        $traceId = 'shared-trace-id';

        $collector->startSpan(createCollectorMockStartEvent('span-1', $traceId));
        $collector->startSpan(createCollectorMockStartEvent('span-2', $traceId));
        $collector->endSpan(createCollectorMockEndEvent('span-1', $traceId));
        $collector->endSpan(createCollectorMockEndEvent('span-2', $traceId));

        expect($capturedSpans)->toHaveCount(2)
            ->and($capturedSpans[0]->spanId)->toBe('span-1')
            ->and($capturedSpans[1]->spanId)->toBe('span-2');
    });
});

function mockDriver(?SpanData &$capturedSpan): TelemetryDriver
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

function captureSpan(
    string $spanId = 'test-span-id',
    string $traceId = 'test-trace-id',
    ?string $parentSpanId = null
): SpanData {
    $capturedSpan = null;
    $driver = mockDriver($capturedSpan);
    $collector = new SpanCollector($driver);

    $collector->startSpan(createCollectorMockStartEvent($spanId, $traceId, $parentSpanId));
    $collector->endSpan(createCollectorMockEndEvent($spanId, $traceId, $parentSpanId));

    return $capturedSpan;
}

function createCollectorMockStartEvent(
    string $spanId = 'test-span-id',
    string $traceId = 'test-trace-id',
    ?string $parentSpanId = null
): TextGenerationStarted {
    return new TextGenerationStarted(
        spanId: $spanId,
        traceId: $traceId,
        parentSpanId: $parentSpanId,
        request: new Request(
            model: 'gpt-4',
            providerKey: 'openai',
            systemPrompts: [],
            prompt: 'Hello',
            messages: [],
            maxSteps: 1,
            maxTokens: 100,
            temperature: 0.7,
            topP: 1.0,
            tools: [],
            clientOptions: [],
            clientRetry: [3],
            toolChoice: null,
        ),
    );
}

function createCollectorMockEndEvent(
    string $spanId = 'test-span-id',
    string $traceId = 'test-trace-id',
    ?string $parentSpanId = null
): TextGenerationCompleted {
    return new TextGenerationCompleted(
        spanId: $spanId,
        traceId: $traceId,
        parentSpanId: $parentSpanId,
        request: new Request(
            model: 'gpt-4',
            providerKey: 'openai',
            systemPrompts: [],
            prompt: 'Hello',
            messages: [],
            maxSteps: 1,
            maxTokens: 100,
            temperature: 0.7,
            topP: 1.0,
            tools: [],
            clientOptions: [],
            clientRetry: [3],
            toolChoice: null,
        ),
        response: new Response(
            steps: new Collection,
            text: 'Hello there!',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(10, 5),
            meta: new Meta(id: 'resp-123', model: 'gpt-4'),
            messages: new Collection,
        ),
    );
}
