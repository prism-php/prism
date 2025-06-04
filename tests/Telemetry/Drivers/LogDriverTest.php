<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Drivers\LogDriver;
use Prism\Prism\Telemetry\ValueObjects\LogSpan;
use Prism\Prism\Telemetry\ValueObjects\SpanStatus;
use Prism\Prism\Telemetry\ValueObjects\TelemetryAttribute;
use Prism\Prism\Testing\LogFake;

beforeEach(function (): void {
    $this->logFake = LogFake::swap();
});

it('implements telemetry driver interface', function (): void {
    $driver = new LogDriver('default', 'info', true);

    expect($driver)->toBeInstanceOf(TelemetryDriver::class);
});

it('creates log spans with correct configuration', function (): void {
    $driver = new LogDriver('default', 'debug', true);

    $span = $driver->startSpan('test-span');

    expect($span)->toBeInstanceOf(LogSpan::class);
    expect($span->getName())->toBe('test-span');
    expect($span->isRecording())->toBeTrue();
});

it('creates log spans with attributes', function (): void {
    $driver = new LogDriver('default', 'info', true);
    $attributes = [
        'test.attribute' => 'value',
        TelemetryAttribute::ProviderName->value => 'openai',
    ];

    $span = $driver->startSpan('test-span', $attributes);

    expect($span)->toBeInstanceOf(LogSpan::class);
    expect($span->getName())->toBe('test-span');
});

it('creates log spans with custom start time', function (): void {
    $driver = new LogDriver('default', 'info', true);
    $startTime = microtime(true) - 1.0;

    $span = $driver->startSpan('test-span', [], $startTime);

    expect($span)->toBeInstanceOf(LogSpan::class);
    expect($span->getStartTime())->toBe($startTime);
});

it('executes callback and returns result', function (): void {
    $driver = new LogDriver('default', 'info', true);
    $expectedResult = 'test-result';

    $result = $driver->span('test-span', [], fn (): string => $expectedResult);

    expect($result)->toBe($expectedResult);
});

it('executes callback with different return types', function (): void {
    $driver = new LogDriver('default', 'info', true);

    $stringResult = $driver->span('test-span', [], fn (): string => 'string-result');
    expect($stringResult)->toBe('string-result');

    $arrayResult = $driver->span('test-span', [], fn (): array => ['key' => 'value']);
    expect($arrayResult)->toBe(['key' => 'value']);

    $objectResult = new stdClass;
    $objectResult->property = 'value';
    $returnedObject = $driver->span('test-span', [], fn (): \stdClass => $objectResult);
    expect($returnedObject)->toBe($objectResult);

    $nullResult = $driver->span('test-span', [], fn (): null => null);
    expect($nullResult)->toBeNull();
});

it('sets ok status on successful execution', function (): void {
    $driver = new LogDriver('default', 'info', true);
    $this->logFake->clear();

    $driver->span('test-span', [], fn (): string => 'success');

    $this->logFake->assertLogged('info', 'test-span');

    $logs = $this->logFake->logged('info', 'test-span');
    $log = $logs->last(); // Get the end log
    expect($log['context']['span.status'])->toBe(SpanStatus::Ok->value);
});

it('sets error status on exception', function (): void {
    $driver = new LogDriver('default', 'info', true);
    $this->logFake->clear();

    try {
        $driver->span('test-span', [], function (): void {
            throw new RuntimeException('Test exception');
        });
    } catch (RuntimeException) {
        // Expected
    }

    $this->logFake->assertLogged('error', 'test-span');

    $logs = $this->logFake->logged('error', 'test-span');
    $log = $logs->first();
    expect($log['context']['span.status'])->toBe(SpanStatus::Error->value);
    expect($log['context']['span.status_description'])->toBe('Test exception');
});

it('propagates exceptions from callback', function (): void {
    $driver = new LogDriver('default', 'info', true);
    $exception = new RuntimeException('Test exception');

    expect(fn (): mixed => $driver->span('test-span', [], function () use ($exception): void {
        throw $exception;
    }))->toThrow(RuntimeException::class, 'Test exception');
});

it('always ends span even when exception occurs', function (): void {
    $driver = new LogDriver('default', 'info', true);
    $this->logFake->clear();

    try {
        $driver->span('test-span', [], function (): void {
            throw new RuntimeException('Test exception');
        });
    } catch (RuntimeException) {
        // Expected
    }

    // Should have logged span completion even with exception
    $this->logFake->assertLogged('error', 'test-span');
});

it('logs to correct channel and level', function (): void {
    $driver = new LogDriver('default', 'debug', true);
    $this->logFake->clear();

    $driver->span('test-span', [], fn (): string => 'success');

    // Should log at debug level since that was configured
    $this->logFake->assertLogged('debug', 'test-span');
});

it('respects include attributes configuration', function (): void {
    $driverWithAttributes = new LogDriver('default', 'info', true);
    $driverWithoutAttributes = new LogDriver('default', 'info', false);

    $attributes = ['test.attribute' => 'value'];

    // With attributes enabled - attributes should be included directly in context
    $this->logFake->clear();
    $driverWithAttributes->span('test-span', $attributes, fn (): string => 'result');

    $logs = $this->logFake->logged('info', 'test-span');
    $log = $logs->last(); // Get the end log
    expect($log['context'])->toHaveKey('test.attribute');
    expect($log['context']['test.attribute'])->toBe('value');

    // With attributes disabled - custom attributes should not be present
    $this->logFake->clear();
    $driverWithoutAttributes->span('test-span', $attributes, fn (): string => 'result');

    $logs = $this->logFake->logged('info', 'test-span');
    $log = $logs->first();
    expect($log['context'])->not->toHaveKey('test.attribute');
});

it('handles multiple spans correctly', function (): void {
    $driver = new LogDriver('default', 'info', true);
    $this->logFake->clear();

    $result1 = $driver->span('span-1', ['id' => 1], fn (): string => 'result-1');
    $result2 = $driver->span('span-2', ['id' => 2], fn (): string => 'result-2');

    expect($result1)->toBe('result-1');
    expect($result2)->toBe('result-2');

    // Should have logged both spans with their respective names
    $this->logFake->assertLogged('info', 'span-1');
    $this->logFake->assertLogged('info', 'span-2');
});

it('handles nested spans through callbacks', function (): void {
    $driver = new LogDriver('default', 'info', true);
    $this->logFake->clear();

    $result = $driver->span('outer-span', [], fn (): mixed => $driver->span('inner-span', [], fn (): string => 'nested-result'));

    expect($result)->toBe('nested-result');
    $this->logFake->assertLogged('info', 'outer-span');
    $this->logFake->assertLogged('info', 'inner-span');
});

it('preserves exception details in span', function (): void {
    $driver = new LogDriver('default', 'info', true);
    $this->logFake->clear();

    try {
        $driver->span('test-span', [], function (): void {
            throw new InvalidArgumentException('Invalid input provided', 1001);
        });
    } catch (InvalidArgumentException) {
        // Expected
    }

    $logs = $this->logFake->logged('error', 'test-span');
    $log = $logs->first();
    expect($log['context']['span.status'])->toBe(SpanStatus::Error->value);
    expect($log['context']['span.status_description'])->toBe('Invalid input provided');
});

it('maintains callback scope correctly', function (): void {
    $driver = new LogDriver('default', 'info', true);
    $outerVariable = 'outer-value';

    $result = $driver->span('test-span', [], fn (): string => $outerVariable);

    expect($result)->toBe('outer-value');
});

it('handles callback that returns false correctly', function (): void {
    $driver = new LogDriver('default', 'info', true);
    $this->logFake->clear();

    $result = $driver->span('test-span', [], fn (): false => false);

    expect($result)->toBeFalse();
    $this->logFake->assertLogged('info', 'test-span');
});

it('records timing information accurately', function (): void {
    $driver = new LogDriver('default', 'info', true);
    $this->logFake->clear();

    $driver->span('test-span', [], function (): void {
        usleep(1000); // 1ms
    });

    $logs = $this->logFake->logged('info', 'test-span');
    $log = $logs->last(); // Get the end log
    expect($log['context'])->toHaveKey('span.duration_ms');
    expect($log['context']['span.duration_ms'])->toBeFloat();
    expect($log['context']['span.duration_ms'])->toBeGreaterThan(0.5); // Should be at least 0.5ms
});
