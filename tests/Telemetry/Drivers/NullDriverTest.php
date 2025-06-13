<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Drivers\NullDriver;

it('returns empty string for span id', function (): void {
    $driver = new NullDriver;

    $spanId = $driver->startSpan('test-operation');

    expect($spanId)->toBe('');
});

it('does not throw exceptions for any operations', function (): void {
    $driver = new NullDriver;

    $driver->startSpan('test', ['key' => 'value']);
    $driver->endSpan('span-id', ['key' => 'value']);
    $driver->addEvent('span-id', 'event', ['key' => 'value']);
    $driver->recordException('span-id', new Exception('test'));

    expect(true)->toBeTrue();
});

it('handles null and empty values gracefully', function (): void {
    $driver = new NullDriver;

    $driver->startSpan('');
    $driver->endSpan('');
    $driver->addEvent('', '');
    $driver->recordException('', new Exception);

    expect(true)->toBeTrue();
});
