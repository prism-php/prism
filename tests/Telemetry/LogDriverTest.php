<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Prism\Prism\Telemetry\LogDriver;

it('can create a span with log driver', function (): void {
    $driver = new LogDriver('test', true);

    // Mock the Log facade to handle all channel calls
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->twice();

    $result = $driver->span('test.span', [
        'test.attribute' => 'test.value',
    ], fn (): string => 'test result');

    expect($result)->toBe('test result');
});

it('can create a child span with log driver', function (): void {
    $driver = new LogDriver('test', true);

    // Mock the Log facade to handle all channel calls
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->twice();

    $result = $driver->childSpan('test.child.span', [
        'test.attribute' => 'test.value',
    ], fn (): string => 'child result');

    expect($result)->toBe('child result');
});

it('handles exceptions in spans', function (): void {
    $driver = new LogDriver('test', true);

    // Mock the Log facade to handle all channel calls
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->twice();

    expect(function () use ($driver): void {
        $driver->span('test.error.span', [], function (): void {
            throw new Exception('Test exception');
        });
    })->toThrow(Exception::class, 'Test exception');
});

it('respects enabled flag', function (): void {
    $driver = new LogDriver('test', false);

    expect($driver->enabled())->toBeFalse();

    // When disabled, no logging should occur
    Log::shouldReceive('channel')->never();
    Log::shouldReceive('info')->never();

    $result = $driver->span('test.span', [], fn (): string => 'disabled result');

    expect($result)->toBe('disabled result');
});
