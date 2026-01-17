<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Prism\Prism\Telemetry\Drivers\LogDriver;
use Prism\Prism\Telemetry\SpanData;

beforeEach(function (): void {
    // Allow any unexpected channel calls (e.g., deprecations channel)
    Log::shouldReceive('channel')->byDefault()->andReturnSelf();
    Log::shouldReceive('warning')->byDefault();
});

it('logs span data when recordSpan is called', function (): void {
    Log::shouldReceive('channel')
        ->with('test-channel')
        ->once()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->once()
        ->with('Span recorded', Mockery::on(fn ($data): bool => $data['span_id'] === 'test-span-id'
            && $data['operation'] === 'text_generation'
            && isset($data['duration_ms'])
            && $data['has_error'] === false
            && isset($data['attributes'])));

    $driver = new LogDriver('test-channel');
    $spanData = createLogSpanData();

    $driver->recordSpan($spanData);
});

it('logs error when span has exception', function (): void {
    Log::shouldReceive('channel')
        ->with('test-channel')
        ->once()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->once()
        ->with('Span recorded', Mockery::on(fn ($data): bool => $data['span_id'] === 'test-span-id'
            && $data['has_error'] === true
            && isset($data['exception'])
            && $data['exception']['class'] === Exception::class
            && $data['exception']['message'] === 'Test exception'));

    $driver = new LogDriver('test-channel');
    $exception = new Exception('Test exception');
    $spanData = createLogSpanData(exception: $exception);

    $driver->recordSpan($spanData);
});

it('handles different channel configurations', function (): void {
    Log::shouldReceive('channel')
        ->with('default')
        ->once()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->once();

    $defaultDriver = new LogDriver;
    $defaultDriver->recordSpan(createLogSpanData());
});

it('logs generic attributes directly from span data', function (): void {
    Log::shouldReceive('channel')
        ->with('test-channel')
        ->once()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->once()
        ->with('Span recorded', Mockery::on(
            fn ($data): bool => $data['attributes']['model'] === 'gpt-4'
            && $data['attributes']['provider'] === 'openai'
            && $data['attributes']['usage']['prompt_tokens'] === 10));

    $driver = new LogDriver('test-channel');
    $spanData = createLogSpanData();

    $driver->recordSpan($spanData);
});

function createLogSpanData(?\Throwable $exception = null): SpanData
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
            'provider' => 'openai',
            'temperature' => 0.7,
            'output' => 'Hello there!',
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ],
        events: [],
        exception: $exception,
    );
}
