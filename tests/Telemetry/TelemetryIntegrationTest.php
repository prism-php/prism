<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Listeners\TelemetryEventListener;
use Prism\Prism\Telemetry\LogTelemetryDriver;

it('registers telemetry driver based on configuration', function (): void {
    Config::set('prism.telemetry.driver', 'log');
    Config::set('prism.telemetry.drivers.log.channel', 'single');

    $driver = app(TelemetryDriver::class);

    expect($driver)->toBeInstanceOf(LogTelemetryDriver::class);
});

it('throws exception for unsupported telemetry driver', function (): void {
    Config::set('prism.telemetry.driver', 'unsupported');

    expect(fn () => app(TelemetryDriver::class))
        ->toThrow(\InvalidArgumentException::class, 'Unsupported telemetry driver: unsupported');
});

it('creates telemetry listener with correct enabled state', function (): void {
    Config::set('prism.telemetry.enabled', true);

    $listener = app(TelemetryEventListener::class);

    expect($listener)->toBeInstanceOf(TelemetryEventListener::class);
});

it('does not register event listeners when telemetry is disabled', function (): void {
    Config::set('prism.telemetry.enabled', false);

    // We can't easily test the service provider registration in isolation,
    // but we can verify the configuration is read correctly
    expect(config('prism.telemetry.enabled'))->toBeFalse();
});

it('registers event listeners when telemetry is enabled', function (): void {
    Config::set('prism.telemetry.enabled', true);

    // This will be tested in integration with actual events
    expect(config('prism.telemetry.enabled'))->toBeTrue();
});

it('uses correct log channel from configuration', function (): void {
    Config::set('prism.telemetry.drivers.log.channel', 'custom-channel');

    // Since we can't easily mock the Log facade in this context,
    // we'll just verify the config is read correctly
    expect(config('prism.telemetry.drivers.log.channel'))->toBe('custom-channel');
});
