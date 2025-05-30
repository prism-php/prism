<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\LogTelemetryDriver;
use Psr\Log\LoggerInterface;

it('can start and end telemetry spans', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $driver = new LogTelemetryDriver($logger);

    $logger->shouldReceive('info')
        ->once()
        ->with('Telemetry span started', Mockery::on(function (array $context): true {
            expect($context)->toHaveKeys(['span_id', 'span_name', 'parent_span_id', 'attributes']);
            expect($context['span_name'])->toBe('test_span');
            expect($context['parent_span_id'])->toBeNull();

            return true;
        }));

    $logger->shouldReceive('info')
        ->once()
        ->with('Telemetry span completed', Mockery::on(function (array $context): true {
            expect($context)->toHaveKeys(['span_id', 'span_name', 'duration_ms']);
            expect($context['span_name'])->toBe('test_span');
            expect($context['duration_ms'])->toBeGreaterThan(0);

            return true;
        }));

    $spanId = $driver->startSpan('test_span', ['test' => 'value']);

    expect($spanId)->toBeString();
    expect(strlen($spanId))->toBe(16); // 8 bytes hex = 16 chars

    $driver->endSpan($spanId, ['result' => 'success']);
});

it('can record exceptions on spans', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $driver = new LogTelemetryDriver($logger);
    $exception = new \Exception('Test exception', 123);

    $logger->shouldReceive('info')->once(); // start span
    $logger->shouldReceive('error')
        ->once()
        ->with('Telemetry span exception', Mockery::on(function (array $context): true {
            expect($context)->toHaveKeys([
                'span_id', 'span_name', 'exception_class',
                'exception_message', 'exception_code',
            ]);
            expect($context['exception_class'])->toBe(\Exception::class);
            expect($context['exception_message'])->toBe('Test exception');
            expect($context['exception_code'])->toBe(123);

            return true;
        }));

    $spanId = $driver->startSpan('error_span');
    $driver->recordException($spanId, $exception);
});

it('handles parent-child span relationships', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $driver = new LogTelemetryDriver($logger);

    $logger->shouldReceive('info')
        ->once()
        ->with('Telemetry span started', Mockery::on(function (array $context): true {
            expect($context['parent_span_id'])->toBeNull();

            return true;
        }));

    $logger->shouldReceive('info')
        ->once()
        ->with('Telemetry span started', Mockery::on(function (array $context): true {
            expect($context['parent_span_id'])->toBeString();

            return true;
        }));

    $parentSpanId = $driver->startSpan('parent_span');
    $childSpanId = $driver->startSpan('child_span', [], $parentSpanId);

    expect($parentSpanId)->not->toBe($childSpanId);
    expect($parentSpanId)->toBeString();
    expect($childSpanId)->toBeString();
});

it('gracefully handles ending non-existent spans', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $driver = new LogTelemetryDriver($logger);

    $logger->shouldNotReceive('info');

    // Should not throw exception or log anything
    $driver->endSpan('non-existent-span-id');
});

it('gracefully handles recording exceptions on non-existent spans', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $driver = new LogTelemetryDriver($logger);
    $exception = new \Exception('Test exception');

    $logger->shouldNotReceive('error');

    // Should not throw exception or log anything
    $driver->recordException('non-existent-span-id', $exception);
});

it('generates unique span IDs', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $driver = new LogTelemetryDriver($logger);

    $logger->shouldReceive('info')->times(100); // Allow many start calls

    $spanIds = [];
    for ($i = 0; $i < 100; $i++) {
        $spanIds[] = $driver->startSpan("span_{$i}");
    }

    // All span IDs should be unique
    expect(count(array_unique($spanIds)))->toBe(100);

    // All should be 16 character hex strings
    foreach ($spanIds as $spanId) {
        expect($spanId)->toMatch('/^[a-f0-9]{16}$/');
    }
});

it('includes all start attributes in span data', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $driver = new LogTelemetryDriver($logger);

    $startAttributes = ['key1' => 'value1', 'key2' => 42, 'key3' => true];

    $logger->shouldReceive('info')
        ->once()
        ->with('Telemetry span started', Mockery::on(function (array $context) use ($startAttributes): true {
            expect($context['attributes'])->toBe($startAttributes);

            return true;
        }));

    $driver->startSpan('test_span', $startAttributes);
});

it('includes both start and end attributes when ending span', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $driver = new LogTelemetryDriver($logger);

    $startAttributes = ['start_key' => 'start_value'];
    $endAttributes = ['end_key' => 'end_value'];

    $logger->shouldReceive('info')->once(); // start span

    $logger->shouldReceive('info')
        ->once()
        ->with('Telemetry span completed', Mockery::on(function (array $context) use ($startAttributes, $endAttributes): true {
            expect($context['start_attributes'])->toBe($startAttributes);
            expect($context['end_attributes'])->toBe($endAttributes);

            return true;
        }));

    $spanId = $driver->startSpan('test_span', $startAttributes);
    $driver->endSpan($spanId, $endAttributes);
});

it('records exception details with file and line information', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $driver = new LogTelemetryDriver($logger);

    $exception = new \Exception('Test exception', 123);

    $logger->shouldReceive('info')->once(); // start span
    $logger->shouldReceive('error')
        ->once()
        ->with('Telemetry span exception', Mockery::on(function (array $context): true {
            expect($context)->toHaveKeys([
                'span_id', 'span_name', 'exception_class',
                'exception_message', 'exception_code',
                'exception_file', 'exception_line',
            ]);
            expect($context['exception_file'])->toContain('LogTelemetryDriverTest.php');
            expect($context['exception_line'])->toBeInt();

            return true;
        }));

    $spanId = $driver->startSpan('error_span');
    $driver->recordException($spanId, $exception);
});
