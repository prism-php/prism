<?php

declare(strict_types=1);

use Prism\Prism\Contracts\Telemetry;
use Prism\Prism\Telemetry\LogDriver;
use Prism\Prism\Telemetry\NullDriver;
use Prism\Prism\Telemetry\OpenTelemetryDriver;

it('can resolve telemetry from container', function (): void {
    $telemetry = app(Telemetry::class);

    expect($telemetry)->toBeInstanceOf(Telemetry::class);
});

it('returns null driver when telemetry is disabled', function (): void {
    config(['prism.telemetry.enabled' => false]);

    $telemetry = app(Telemetry::class);

    expect($telemetry)->toBeInstanceOf(NullDriver::class)
        ->and($telemetry->enabled())->toBeFalse();
});

it('null driver executes callback without tracing', function (): void {
    $driver = new NullDriver;
    $called = false;

    $result = $driver->span('test.span', ['foo' => 'bar'], function () use (&$called): string {
        $called = true;

        return 'test-result';
    });

    expect($called)->toBeTrue()
        ->and($result)->toBe('test-result');
});

it('null driver child span executes callback without tracing', function (): void {
    $driver = new NullDriver;
    $called = false;

    $result = $driver->childSpan('test.child', ['foo' => 'bar'], function () use (&$called): string {
        $called = true;

        return 'child-result';
    });

    expect($called)->toBeTrue()
        ->and($result)->toBe('child-result');
});

it('resolves null driver when configured with class name', function (): void {
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.driver' => NullDriver::class,
    ]);

    $telemetry = app(Telemetry::class);

    expect($telemetry)->toBeInstanceOf(NullDriver::class);
});

it('resolves log driver when configured with class name', function (): void {
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.driver' => LogDriver::class,
        'prism.telemetry.log_channel' => 'test',
    ]);

    $telemetry = app(Telemetry::class);

    expect($telemetry)->toBeInstanceOf(LogDriver::class)
        ->and($telemetry->enabled())->toBeTrue();
});

it('resolves opentelemetry driver when configured with class name', function (): void {
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.driver' => OpenTelemetryDriver::class,
    ]);

    $telemetry = app(Telemetry::class);

    expect($telemetry)->toBeInstanceOf(OpenTelemetryDriver::class)
        ->and($telemetry->enabled())->toBeTrue();
});

it('falls back to null driver when telemetry is disabled even with class name', function (): void {
    config([
        'prism.telemetry.enabled' => false,
        'prism.telemetry.driver' => LogDriver::class,
    ]);

    $telemetry = app(Telemetry::class);

    expect($telemetry)->toBeInstanceOf(NullDriver::class);
});
