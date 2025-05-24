<?php

declare(strict_types=1);

use Prism\Prism\Contracts\Telemetry;
use Prism\Prism\Telemetry\ArrayLogDriver;
use Prism\Prism\Telemetry\LogDriver;

it('can resolve telemetry from container', function (): void {
    $telemetry = app(Telemetry::class);

    expect($telemetry)->toBeInstanceOf(Telemetry::class);
});

it('returns log driver with disabled state when telemetry is disabled', function (): void {
    config(['prism.telemetry.enabled' => false]);

    $telemetry = app(Telemetry::class);

    expect($telemetry)->toBeInstanceOf(LogDriver::class)
        ->and($telemetry->enabled())->toBeFalse();
});

it('disabled log driver executes callback without tracing', function (): void {
    $driver = new LogDriver(enabled: false);
    $called = false;

    $result = $driver->span('test.span', ['foo' => 'bar'], function () use (&$called): string {
        $called = true;

        return 'test-result';
    });

    expect($called)->toBeTrue()
        ->and($result)->toBe('test-result')
        ->and($driver->enabled())->toBeFalse();
});

it('disabled log driver child span executes callback without tracing', function (): void {
    $driver = new LogDriver(enabled: false);
    $called = false;

    $result = $driver->childSpan('test.child', ['foo' => 'bar'], function () use (&$called): string {
        $called = true;

        return 'child-result';
    });

    expect($called)->toBeTrue()
        ->and($result)->toBe('child-result')
        ->and($driver->enabled())->toBeFalse();
});

it('resolves log driver when telemetry is enabled', function (): void {
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.log_channel' => 'test',
    ]);

    $telemetry = app(Telemetry::class);

    expect($telemetry)->toBeInstanceOf(LogDriver::class)
        ->and($telemetry->enabled())->toBeTrue();
});

it('resolves log driver with custom channel', function (): void {
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.log_channel' => 'custom',
    ]);

    $telemetry = app(Telemetry::class);

    expect($telemetry)->toBeInstanceOf(LogDriver::class)
        ->and($telemetry->enabled())->toBeTrue();
});

it('returns disabled log driver when telemetry is disabled', function (): void {
    config([
        'prism.telemetry.enabled' => false,
        'prism.telemetry.log_channel' => 'custom',
    ]);

    $telemetry = app(Telemetry::class);

    expect($telemetry)->toBeInstanceOf(LogDriver::class)
        ->and($telemetry->enabled())->toBeFalse();
});

it('array log driver logs span start and end events', function (): void {
    $driver = new ArrayLogDriver(enabled: true);
    $called = false;

    $result = $driver->span('test.span', ['foo' => 'bar'], function () use (&$called): string {
        $called = true;

        return 'test-result';
    });

    $logs = $driver->getLogs();

    expect($called)->toBeTrue()
        ->and($result)->toBe('test-result')
        ->and($logs)->toHaveCount(2);

    // Check span start log
    expect($logs[0])
        ->toHaveKey('level', 'info')
        ->toHaveKey('message', 'Prism span started: test.span')
        ->toHaveKey('context');

    expect($logs[0]['context'])
        ->toHaveKey('prism.telemetry.span_name', 'test.span')
        ->toHaveKey('prism.telemetry.event', 'span.start')
        ->toHaveKey('prism.telemetry.span_id')
        ->toHaveKey('prism.telemetry.timestamp')
        ->toHaveKey('foo', 'bar');

    // Check span end log
    expect($logs[1])
        ->toHaveKey('level', 'info')
        ->toHaveKey('context');

    expect($logs[1]['context'])
        ->toHaveKey('prism.telemetry.span_name', 'test.span')
        ->toHaveKey('prism.telemetry.event', 'span.end')
        ->toHaveKey('prism.telemetry.span_id')
        ->toHaveKey('prism.telemetry.duration_ms')
        ->toHaveKey('prism.telemetry.status', 'success')
        ->toHaveKey('foo', 'bar');

    expect($logs[1]['message'])->toContain('Prism span completed: test.span');
});

it('array log driver logs child span with correct type', function (): void {
    $driver = new ArrayLogDriver(enabled: true);

    $driver->childSpan('test.child', ['foo' => 'bar'], fn(): string => 'child-result');

    $logs = $driver->getLogs();

    expect($logs)->toHaveCount(2);

    // Check that both logs have the child span type
    expect($logs[0]['context'])
        ->toHaveKey('prism.telemetry.span_type', 'child');

    expect($logs[1]['context'])
        ->toHaveKey('prism.telemetry.span_type', 'child');
});

it('array log driver logs error information when exception is thrown', function (): void {
    $driver = new ArrayLogDriver(enabled: true);

    try {
        $driver->span('test.error', ['foo' => 'bar'], function (): void {
            throw new \RuntimeException('Test error message');
        });
    } catch (\RuntimeException) {
        // Expected exception
    }

    $logs = $driver->getLogs();

    expect($logs)->toHaveCount(2);

    // Check span end log with error information
    expect($logs[1]['context'])
        ->toHaveKey('prism.telemetry.status', 'error')
        ->toHaveKey('prism.telemetry.error.class', \RuntimeException::class)
        ->toHaveKey('prism.telemetry.error.message', 'Test error message')
        ->toHaveKey('prism.telemetry.error.file')
        ->toHaveKey('prism.telemetry.error.line');

    expect($logs[1]['message'])->toContain('Prism span failed: test.error');
});

it('disabled array log driver executes callback without logging', function (): void {
    $driver = new ArrayLogDriver(enabled: false);
    $called = false;

    $result = $driver->span('test.span', ['foo' => 'bar'], function () use (&$called): string {
        $called = true;

        return 'test-result';
    });

    expect($called)->toBeTrue()
        ->and($result)->toBe('test-result')
        ->and($driver->getLogs())->toBeEmpty()
        ->and($driver->enabled())->toBeFalse();
});

it('array log driver can clear logs', function (): void {
    $driver = new ArrayLogDriver(enabled: true);

    $driver->span('test.span', [], fn(): string => 'result');

    expect($driver->getLogs())->toHaveCount(2);

    $driver->clearLogs();

    expect($driver->getLogs())->toBeEmpty();
});
