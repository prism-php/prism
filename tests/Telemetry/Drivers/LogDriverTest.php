<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Drivers\LogDriver;

it('returns uuid for span start', function (): void {
    $driver = new LogDriver('test-channel');
    $spanId = $driver->startSpan('test-operation', ['key' => 'value']);

    expect($spanId)->toBeString();
    expect($spanId)->not->toBeEmpty();
    expect($spanId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

it('does not throw exceptions for span operations', function (): void {
    $driver = new LogDriver('test-channel');

    $spanId = $driver->startSpan('test-operation', ['key' => 'value']);
    $driver->endSpan($spanId, ['duration' => 100]);
    $driver->addEvent($spanId, 'http.request', ['url' => 'https://example.com']);
    $driver->recordException($spanId, new Exception('Test exception'));

    expect(true)->toBeTrue();
});

it('handles different channel configurations', function (): void {
    $defaultDriver = new LogDriver;
    $customDriver = new LogDriver('custom-channel');

    $defaultSpanId = $defaultDriver->startSpan('test-operation');
    $customSpanId = $customDriver->startSpan('test-operation');

    expect($defaultSpanId)->toBeString();
    expect($customSpanId)->toBeString();
    expect($defaultSpanId)->not->toBe($customSpanId);
});

it('handles empty attributes gracefully', function (): void {
    $driver = new LogDriver('test-channel');

    $spanId = $driver->startSpan('test-operation');
    $driver->endSpan($spanId);
    $driver->addEvent($spanId, 'event');

    expect($spanId)->toBeString();
});

it('handles exception logging', function (): void {
    $driver = new LogDriver('test-channel');
    $exception = new Exception('Test exception', 123);

    $driver->recordException('span-id', $exception);

    expect(true)->toBeTrue();
});
