<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Drivers\NullDriver;
use Prism\Prism\Telemetry\ValueObjects\NullSpan;
use Prism\Prism\Telemetry\ValueObjects\TelemetryAttribute;

it('implements telemetry driver interface', function (): void {
    $driver = new NullDriver;

    expect($driver)->toBeInstanceOf(TelemetryDriver::class);
});

it('creates null spans', function (): void {
    $driver = new NullDriver;

    $span = $driver->startSpan('test-span');

    expect($span)->toBeInstanceOf(NullSpan::class);
    expect($span->getName())->toBe('test-span');
    expect($span->isRecording())->toBeFalse();
});

it('creates null spans with attributes', function (): void {
    $driver = new NullDriver;
    $attributes = [
        'test.attribute' => 'value',
        TelemetryAttribute::ProviderName->value => 'openai',
    ];

    $span = $driver->startSpan('test-span', $attributes);

    expect($span)->toBeInstanceOf(NullSpan::class);
    expect($span->getName())->toBe('test-span');
});

it('creates null spans with custom start time', function (): void {
    $driver = new NullDriver;
    $startTime = microtime(true) - 1.0;

    $span = $driver->startSpan('test-span', [], $startTime);

    expect($span)->toBeInstanceOf(NullSpan::class);
    expect($span->getStartTime())->toBe($startTime);
});

it('executes callback without telemetry overhead', function (): void {
    $driver = new NullDriver;
    $executed = false;
    $expectedResult = 'test-result';

    $result = $driver->span('test-span', [], function () use (&$executed, $expectedResult): string {
        $executed = true;

        return $expectedResult;
    });

    expect($executed)->toBeTrue();
    expect($result)->toBe($expectedResult);
});

it('executes callback with attributes', function (): void {
    $driver = new NullDriver;
    $executed = false;
    $attributes = [
        'test.attribute' => 'value',
        TelemetryAttribute::ProviderName->value => 'openai',
    ];

    $driver->span('test-span', $attributes, function () use (&$executed): void {
        $executed = true;
    });

    expect($executed)->toBeTrue();
});

it('returns callback result unchanged', function (): void {
    $driver = new NullDriver;

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

    $boolResult = $driver->span('test-span', [], fn (): true => true);
    expect($boolResult)->toBeTrue();
});

it('propagates exceptions from callback', function (): void {
    $driver = new NullDriver;
    $exception = new RuntimeException('Test exception');

    expect(fn (): mixed => $driver->span('test-span', [], function () use ($exception): void {
        throw $exception;
    }))->toThrow(RuntimeException::class, 'Test exception');
});

it('handles multiple types of exceptions', function (): void {
    $driver = new NullDriver;

    // RuntimeException
    expect(fn (): mixed => $driver->span('test-span', [], function (): void {
        throw new RuntimeException('Runtime error');
    }))->toThrow(RuntimeException::class, 'Runtime error');

    // InvalidArgumentException
    expect(fn (): mixed => $driver->span('test-span', [], function (): void {
        throw new InvalidArgumentException('Invalid argument');
    }))->toThrow(InvalidArgumentException::class, 'Invalid argument');

    // Custom exception
    $customException = new class('Custom error') extends Exception {};
    expect(fn (): mixed => $driver->span('test-span', [], function () use ($customException): void {
        throw $customException;
    }))->toThrow(Exception::class, 'Custom error');
});

it('has zero performance overhead', function (): void {
    $driver = new NullDriver;
    $iterations = 1000;

    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $driver->span("span-{$i}", ['iteration' => $i], fn(): string => 'result');
    }

    $duration = microtime(true) - $start;

    // Should complete very quickly (less than 10ms for 1000 iterations)
    expect($duration)->toBeLessThan(0.01);
});

it('maintains callback scope correctly', function (): void {
    $driver = new NullDriver;
    $outerVariable = 'outer-value';

    $result = $driver->span('test-span', [], fn(): string => $outerVariable);

    expect($result)->toBe('outer-value');
});

it('supports callback with parameters', function (): void {
    $driver = new NullDriver;

    $callback = fn($param1, $param2): string => $param1.'-'.$param2;

    // Note: The span method doesn't pass parameters to callback in the current implementation
    // This test verifies the callback can still access its closure variables
    $param1 = 'value1';
    $param2 = 'value2';

    $result = $driver->span('test-span', [], fn(): string => $callback($param1, $param2));

    expect($result)->toBe('value1-value2');
});
