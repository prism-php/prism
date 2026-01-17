<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Drivers\NullDriver;
use Prism\Prism\Telemetry\SpanData;

it('does not throw when recording a span', function (): void {
    $driver = new NullDriver;
    $spanData = createNullDriverSpanData();

    $driver->recordSpan($spanData);

    expect(true)->toBeTrue();
});

it('handles spans with exceptions gracefully', function (): void {
    $driver = new NullDriver;
    $spanData = createNullDriverSpanData(new Exception('Test exception'));

    $driver->recordSpan($spanData);

    expect(true)->toBeTrue();
});

it('handles spans with events gracefully', function (): void {
    $driver = new NullDriver;

    $spanData = new SpanData(
        spanId: 'test-span-id',
        traceId: bin2hex(random_bytes(16)),
        parentSpanId: null,
        operation: 'text_generation',
        startTimeNano: (int) (microtime(true) * 1_000_000_000),
        endTimeNano: (int) (microtime(true) * 1_000_000_000) + 100_000_000,
        attributes: ['model' => 'gpt-4'],
        events: [
            ['name' => 'event1', 'timeNanos' => 1000, 'attributes' => []],
            ['name' => 'event2', 'timeNanos' => 2000, 'attributes' => ['key' => 'value']],
        ],
    );

    $driver->recordSpan($spanData);

    expect(true)->toBeTrue();
});

// ============================================================================
// Helper Functions
// ============================================================================

function createNullDriverSpanData(?\Throwable $exception = null): SpanData
{
    return new SpanData(
        spanId: 'test-span-id',
        traceId: bin2hex(random_bytes(16)),
        parentSpanId: null,
        operation: 'text_generation',
        startTimeNano: (int) (microtime(true) * 1_000_000_000),
        endTimeNano: (int) (microtime(true) * 1_000_000_000) + 100_000_000,
        attributes: [
            'model' => 'gpt-4',
        ],
        events: [],
        exception: $exception,
    );
}
