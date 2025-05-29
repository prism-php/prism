<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\LogTelemetryDriver;
use Prism\Prism\TelemetryManager;

it('can create log driver', function (): void {
    Config::set('prism.telemetry.drivers.log.channel', 'single');

    $manager = new TelemetryManager($this->app);

    $driver = $manager->driver('log');

    expect($driver)->toBeInstanceOf(LogTelemetryDriver::class);
});

it('uses default driver from configuration', function (): void {
    Config::set('prism.telemetry.driver', 'log');

    $manager = new TelemetryManager($this->app);

    expect($manager->getDefaultDriver())->toBe('log');
});

it('can extend with custom drivers', function (): void {
    $manager = new TelemetryManager($this->app);

    $customDriver = mock(TelemetryDriver::class);

    $manager->extend('custom', function (Application $app) use ($customDriver) {
        expect($app)->toBeInstanceOf(Application::class);

        return $customDriver;
    });

    $driver = $manager->driver('custom');

    expect($driver)->toBe($customDriver);
});

it('passes correct configuration to log driver', function (): void {
    Config::set('prism.telemetry.drivers.log.channel', 'custom-channel');

    $manager = new TelemetryManager($this->app);

    // The createLogDriver method should receive the configuration
    $driver = $manager->driver('log');

    expect($driver)->toBeInstanceOf(LogTelemetryDriver::class);
});

it('returns same driver instance when called multiple times', function (): void {
    $manager = new TelemetryManager($this->app);

    $driver1 = $manager->driver('log');
    $driver2 = $manager->driver('log');

    expect($driver1)->toBe($driver2);
});
