<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Prism\Prism\Telemetry\Jobs\ExportSpanJob;
use Prism\Prism\Telemetry\SpanData;

beforeEach(function (): void {
    Queue::fake();
});

it('implements ShouldQueue interface', function (): void {
    $job = new ExportSpanJob(createJobSpanData(), 'test_driver');

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

it('has correct retry configuration', function (): void {
    $job = new ExportSpanJob(createJobSpanData(), 'test_driver');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(5);
});

it('uses default driver when not specified', function (): void {
    $job = new ExportSpanJob(createJobSpanData());

    expect(getJobProperty($job, 'driver'))->toBe('otlp');
});

describe('queue configuration', function (): void {
    it('uses default queue when not configured', function (): void {
        config(['prism.telemetry.drivers.test_driver' => []]);

        $job = new ExportSpanJob(createJobSpanData(), 'test_driver');

        expect(getJobProperty($job, 'queue'))->toBe('default');
    });

    it('uses configured queue', function (): void {
        config(['prism.telemetry.drivers.test_driver' => ['queue' => 'telemetry-queue']]);

        $job = new ExportSpanJob(createJobSpanData(), 'test_driver');

        expect(getJobProperty($job, 'queue'))->toBe('telemetry-queue');
    });
});

describe('serialization', function (): void {
    it('can be serialized and unserialized for queue', function (): void {
        $job = new ExportSpanJob(createJobSpanData(), 'test_driver');

        $unserialized = unserialize(serialize($job));

        expect($unserialized)->toBeInstanceOf(ExportSpanJob::class);
    });

    it('preserves span data through serialization', function (): void {
        $spanData = createJobSpanData('unique-span-id', 'unique-trace-id');
        $job = new ExportSpanJob($spanData, 'test_driver');

        $unserialized = unserialize(serialize($job));
        $unserializedSpanData = getJobProperty($unserialized, 'spanData');

        expect($unserializedSpanData->spanId)->toBe('unique-span-id')
            ->and($unserializedSpanData->traceId)->toBe('unique-trace-id');
    });

    it('preserves driver name through serialization', function (): void {
        $job = new ExportSpanJob(createJobSpanData(), 'custom_driver');

        $unserialized = unserialize(serialize($job));

        expect(getJobProperty($unserialized, 'driver'))->toBe('custom_driver');
    });
});

describe('job tags', function (): void {
    it('includes common tags for all operations', function (): void {
        $job = new ExportSpanJob(createJobSpanData(), 'phoenix');
        $tags = $job->tags();

        expect($tags)->toContain('prism')
            ->and($tags)->toContain('telemetry')
            ->and($tags)->toContain('phoenix');
    });

    it('generates operation-specific tags', function (): void {
        $textJob = new ExportSpanJob(createJobSpanData(), 'otlp');
        expect($textJob->tags())->toContain('span:text_generation');

        $toolJob = new ExportSpanJob(createSpanDataWithOperation('tool_call'), 'otlp');
        expect($toolJob->tags())->toContain('span:tool_call');

        $streamJob = new ExportSpanJob(createSpanDataWithOperation('streaming'), 'phoenix');
        expect($streamJob->tags())->toContain('span:streaming');
    });
});

describe('data preservation', function (): void {
    it('stores span data and driver name correctly', function (): void {
        $spanData = createJobSpanData();
        $job = new ExportSpanJob($spanData, 'custom_driver_name');

        expect(getJobProperty($job, 'spanData'))->toBe($spanData)
            ->and(getJobProperty($job, 'driver'))->toBe('custom_driver_name');
    });

    it('preserves error status in span data', function (): void {
        $exception = new RuntimeException('Test error');
        $spanData = createSpanDataWithOperation('text_generation', $exception);

        $job = new ExportSpanJob($spanData, 'test_driver');
        $storedSpanData = getJobProperty($job, 'spanData');

        expect($storedSpanData->hasError())->toBeTrue()
            ->and($storedSpanData->exception)->toBe($exception);
    });

    it('preserves events in span data', function (): void {
        $events = [
            ['name' => 'chunk_received', 'timeNano' => (int) (microtime(true) * 1e9), 'attributes' => ['size' => 100]],
            ['name' => 'token_generated', 'timeNano' => (int) (microtime(true) * 1e9), 'attributes' => ['count' => 5]],
        ];

        $spanData = new SpanData(
            spanId: bin2hex(random_bytes(8)),
            traceId: bin2hex(random_bytes(16)),
            parentSpanId: null,
            operation: 'text_generation',
            startTimeNano: (int) (microtime(true) * 1e9),
            endTimeNano: (int) (microtime(true) * 1e9) + 1000000,
            attributes: ['model' => 'gpt-4'],
            events: $events,
        );

        $job = new ExportSpanJob($spanData, 'test_driver');
        $storedSpanData = getJobProperty($job, 'spanData');

        expect($storedSpanData->events)->toHaveCount(2)
            ->and($storedSpanData->events[0]['name'])->toBe('chunk_received');
    });
});

function createJobSpanData(?string $spanId = null, ?string $traceId = null): SpanData
{
    return new SpanData(
        spanId: $spanId ?? bin2hex(random_bytes(8)),
        traceId: $traceId ?? bin2hex(random_bytes(16)),
        parentSpanId: null,
        operation: 'text_generation',
        startTimeNano: (int) (microtime(true) * 1e9),
        endTimeNano: (int) (microtime(true) * 1e9) + 1000000,
        attributes: ['model' => 'gpt-4', 'provider' => 'openai'],
    );
}

function createSpanDataWithOperation(string $operation, ?Throwable $exception = null): SpanData
{
    return new SpanData(
        spanId: bin2hex(random_bytes(8)),
        traceId: bin2hex(random_bytes(16)),
        parentSpanId: null,
        operation: $operation,
        startTimeNano: (int) (microtime(true) * 1e9),
        endTimeNano: (int) (microtime(true) * 1e9) + 1000000,
        attributes: ['model' => 'gpt-4'],
        exception: $exception,
    );
}

function getJobProperty(ExportSpanJob $job, string $property): mixed
{
    $reflection = new ReflectionClass($job);
    $prop = $reflection->getProperty($property);

    return $prop->getValue($job);
}
