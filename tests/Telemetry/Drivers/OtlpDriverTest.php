<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Prism\Prism\Telemetry\Drivers\OtlpDriver;
use Prism\Prism\Telemetry\Jobs\ExportSpanJob;
use Prism\Prism\Telemetry\SpanData;

beforeEach(function (): void {
    Queue::fake();
});

it('dispatches job when recording a span', function (): void {
    $driver = new OtlpDriver('otlp');

    $spanData = createOtlpSpanData();
    $driver->recordSpan($spanData);

    Queue::assertPushed(ExportSpanJob::class);
});

it('passes SpanData object and driver name to job', function (): void {
    $driver = new OtlpDriver('phoenix');

    $spanData = createOtlpSpanData();
    $driver->recordSpan($spanData);

    Queue::assertPushed(ExportSpanJob::class, function ($job): bool {
        $reflection = new ReflectionClass($job);

        $spanDataProp = $reflection->getProperty('spanData');
        $jobSpanData = $spanDataProp->getValue($job);

        $driverProp = $reflection->getProperty('driver');
        $jobDriver = $driverProp->getValue($job);

        return $jobSpanData instanceof SpanData
            && $jobSpanData->attributes['model'] === 'gpt-4'
            && $jobDriver === 'phoenix';
    });
});

it('returns driver name', function (): void {
    $driver = new OtlpDriver('phoenix');

    expect($driver->getDriver())->toBe('phoenix');
});

it('defaults to otlp driver name', function (): void {
    $driver = new OtlpDriver;

    expect($driver->getDriver())->toBe('otlp');
});

it('passes SpanData with error to job', function (): void {
    $driver = new OtlpDriver('otlp');

    $spanData = new SpanData(
        spanId: bin2hex(random_bytes(8)),
        traceId: bin2hex(random_bytes(16)),
        parentSpanId: null,
        operation: 'text_generation',
        startTimeNano: (int) (microtime(true) * 1_000_000_000),
        endTimeNano: (int) (microtime(true) * 1_000_000_000) + 100_000_000,
        attributes: ['model' => 'gpt-4'],
        events: [
            [
                'name' => 'exception',
                'timeNano' => (int) (microtime(true) * 1_000_000_000),
                'attributes' => ['type' => 'RuntimeException', 'message' => 'Test error'],
            ],
        ],
        exception: new RuntimeException('Test error'),
    );

    $driver->recordSpan($spanData);

    Queue::assertPushed(ExportSpanJob::class, function ($job): bool {
        $reflection = new ReflectionClass($job);
        $property = $reflection->getProperty('spanData');
        $spanData = $property->getValue($job);

        return $spanData instanceof SpanData && $spanData->hasError();
    });
});

it('passes all span data fields to job', function (): void {
    $driver = new OtlpDriver('otlp');

    $spanData = createOtlpSpanData();
    $driver->recordSpan($spanData);

    Queue::assertPushed(ExportSpanJob::class, function ($job) use ($spanData): bool {
        $reflection = new ReflectionClass($job);
        $property = $reflection->getProperty('spanData');
        $jobSpanData = $property->getValue($job);

        return $jobSpanData->spanId === $spanData->spanId
            && $jobSpanData->traceId === $spanData->traceId
            && $jobSpanData->operation === $spanData->operation
            && $jobSpanData->startTimeNano === $spanData->startTimeNano
            && $jobSpanData->endTimeNano === $spanData->endTimeNano;
    });
});

it('dispatches to queue when sync is false', function (): void {
    config(['prism.telemetry.drivers.otlp.sync' => false]);

    $driver = new OtlpDriver('otlp');
    $driver->recordSpan(createOtlpSpanData());

    Queue::assertPushed(ExportSpanJob::class);
});

it('executes synchronously when sync is true', function (): void {
    \Illuminate\Support\Facades\Http::fake([
        '*' => \Illuminate\Support\Facades\Http::response('', 200),
    ]);

    config([
        'prism.telemetry.drivers.test_sync' => [
            'sync' => true,
            'endpoint' => 'http://localhost:4318/v1/traces',
            'mapper' => \Prism\Prism\Telemetry\Semantics\PassthroughMapper::class,
        ],
    ]);

    $driver = new OtlpDriver('test_sync');
    $driver->recordSpan(createOtlpSpanData());

    // When sync=true, job should NOT be dispatched to queue
    Queue::assertNotPushed(ExportSpanJob::class);
});

// ============================================================================
// Helper Functions
// ============================================================================

function createOtlpSpanData(): SpanData
{
    return new SpanData(
        spanId: bin2hex(random_bytes(8)),
        traceId: bin2hex(random_bytes(16)),
        parentSpanId: null,
        operation: 'text_generation',
        startTimeNano: (int) (microtime(true) * 1_000_000_000),
        endTimeNano: (int) (microtime(true) * 1_000_000_000) + 100_000_000,
        attributes: [
            'model' => 'gpt-4',
            'provider' => 'openai',
            'temperature' => 0.7,
            'max_tokens' => 100,
            'input' => 'Hello',
            'output' => 'Hello there!',
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
        ],
        events: [],
    );
}
