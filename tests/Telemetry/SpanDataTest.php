<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\SpanData;

it('creates immutable span data with all properties', function (): void {
    $spanData = new SpanData(
        spanId: 'span-123',
        traceId: 'trace-456',
        parentSpanId: 'parent-789',
        operation: 'text_generation',
        startTimeNano: 1000000000,
        endTimeNano: 2000000000,
        attributes: ['model' => 'gpt-4'],
        events: [['name' => 'event1', 'timeNanos' => 1500000000, 'attributes' => []]],
    );

    expect($spanData->spanId)->toBe('span-123')
        ->and($spanData->traceId)->toBe('trace-456')
        ->and($spanData->parentSpanId)->toBe('parent-789')
        ->and($spanData->operation)->toBe('text_generation')
        ->and($spanData->startTimeNano)->toBe(1000000000)
        ->and($spanData->endTimeNano)->toBe(2000000000)
        ->and($spanData->attributes)->toBe(['model' => 'gpt-4'])
        ->and($spanData->events)->toHaveCount(1)
        ->and($spanData->exception)->toBeNull();
});

it('uses sensible defaults for optional parameters', function (): void {
    $spanData = new SpanData(
        spanId: 'span-123',
        traceId: 'trace-456',
        parentSpanId: null,
        operation: 'text_generation',
        startTimeNano: 1000000000,
        endTimeNano: 2000000000,
        attributes: [],
    );

    expect($spanData->parentSpanId)->toBeNull()
        ->and($spanData->events)->toBe([])
        ->and($spanData->exception)->toBeNull();
});

describe('hasError', function (): void {
    it('returns false when exception is null', function (): void {
        $spanData = createSpanDataWithException(null);

        expect($spanData->hasError())->toBeFalse();
    });

    it('returns true when exception is present', function (): void {
        $spanData = createSpanDataWithException(new RuntimeException('Test error'));

        expect($spanData->hasError())->toBeTrue();
    });

    it('returns true for any Throwable type', function (): void {
        $spanData = createSpanDataWithException(new Error('Fatal error'));

        expect($spanData->hasError())->toBeTrue();
    });
});

describe('durationNano', function (): void {
    it('calculates duration in nanoseconds', function (): void {
        $spanData = createSpanDataWithTimes(1000000000, 2500000000);

        expect($spanData->durationNano())->toBe(1500000000);
    });

    it('returns zero for same start and end time', function (): void {
        $spanData = createSpanDataWithTimes(1000000000, 1000000000);

        expect($spanData->durationNano())->toBe(0);
    });

    it('handles very small durations (1 nanosecond)', function (): void {
        $spanData = createSpanDataWithTimes(1000000000, 1000000001);

        expect($spanData->durationNano())->toBe(1);
    });
});

describe('durationMs', function (): void {
    it('calculates duration in milliseconds', function (): void {
        $spanData = createSpanDataWithTimes(1000000000, 2500000000);

        expect($spanData->durationMs())->toBe(1500.0);
    });

    it('returns float for sub-millisecond precision', function (): void {
        $spanData = createSpanDataWithTimes(1000000000, 1000500000);

        expect($spanData->durationMs())->toBe(0.5);
    });

    it('returns zero for same start and end time', function (): void {
        $spanData = createSpanDataWithTimes(1000000000, 1000000000);

        expect($spanData->durationMs())->toBe(0.0);
    });
});

describe('attributes', function (): void {
    it('supports nested arrays and complex structures', function (): void {
        $spanData = new SpanData(
            spanId: 'span-123',
            traceId: 'trace-456',
            parentSpanId: null,
            operation: 'text_generation',
            startTimeNano: 1000000000,
            endTimeNano: 2000000000,
            attributes: [
                'model' => 'gpt-4',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
                'messages' => [
                    ['role' => 'user', 'content' => 'Hello'],
                    ['role' => 'assistant', 'content' => 'Hi there!'],
                ],
            ],
        );

        expect($spanData->attributes['usage']['prompt_tokens'])->toBe(10)
            ->and($spanData->attributes['messages'])->toHaveCount(2);
    });

    it('supports empty attributes', function (): void {
        $spanData = createSpanDataWithTimes(1000000000, 2000000000);

        expect($spanData->attributes)->toBe([]);
    });
});

describe('events', function (): void {
    it('supports multiple events and preserves order', function (): void {
        $spanData = new SpanData(
            spanId: 'span-123',
            traceId: 'trace-456',
            parentSpanId: null,
            operation: 'text_generation',
            startTimeNano: 1000000000,
            endTimeNano: 2000000000,
            attributes: [],
            events: [
                ['name' => 'first', 'timeNanos' => 1100000000, 'attributes' => ['size' => 10]],
                ['name' => 'second', 'timeNanos' => 1200000000, 'attributes' => ['size' => 15]],
                ['name' => 'third', 'timeNanos' => 1300000000, 'attributes' => []],
            ],
        );

        expect($spanData->events)->toHaveCount(3)
            ->and($spanData->events[0]['name'])->toBe('first')
            ->and($spanData->events[1]['name'])->toBe('second')
            ->and($spanData->events[2]['name'])->toBe('third');
    });
});

function createSpanDataWithException(?Throwable $exception): SpanData
{
    return new SpanData(
        spanId: 'span-123',
        traceId: 'trace-456',
        parentSpanId: null,
        operation: 'text_generation',
        startTimeNano: 1000000000,
        endTimeNano: 2000000000,
        attributes: [],
        exception: $exception,
    );
}

function createSpanDataWithTimes(int $startTimeNano, int $endTimeNano): SpanData
{
    return new SpanData(
        spanId: 'span-123',
        traceId: 'trace-456',
        parentSpanId: null,
        operation: 'text_generation',
        startTimeNano: $startTimeNano,
        endTimeNano: $endTimeNano,
        attributes: [],
    );
}
