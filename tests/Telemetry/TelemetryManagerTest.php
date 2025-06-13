<?php

declare(strict_types=1);

use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Drivers\LogDriver;
use Prism\Prism\Telemetry\Drivers\NullDriver;
use Prism\Prism\Telemetry\TelemetryManager;

it('resolves null driver by default', function (): void {
    $manager = new TelemetryManager(app());

    $driver = $manager->resolve('null');

    expect($driver)->toBeInstanceOf(NullDriver::class);
});

it('resolves log driver with configuration', function (): void {
    $manager = new TelemetryManager(app());

    $driver = $manager->resolve('log', ['channel' => 'test']);

    expect($driver)->toBeInstanceOf(LogDriver::class);
});

it('throws exception for unsupported driver', function (): void {
    $manager = new TelemetryManager(app());

    $manager->resolve('unsupported');
})->throws(InvalidArgumentException::class, 'Telemetry driver [unsupported] is not supported.');

it('allows custom driver extension', function (): void {
    $manager = new TelemetryManager(app());

    $customDriver = new class implements TelemetryDriver
    {
        public function startSpan(string $operation, array $attributes = []): string
        {
            return 'custom-span';
        }

        public function endSpan(string $spanId, array $attributes = []): void {}

        public function addEvent(string $spanId, string $name, array $attributes = []): void {}

        public function recordException(string $spanId, \Throwable $exception): void {}
    };

    $manager->extend('custom', fn ($app, $config): object => $customDriver);

    $resolvedDriver = $manager->resolve('custom');

    expect($resolvedDriver)->toBe($customDriver);
});

it('uses configuration from config file', function (): void {
    config([
        'prism.telemetry.drivers.test' => [
            'channel' => 'custom-channel',
        ],
    ]);

    $manager = new TelemetryManager(app());
    $driver = $manager->resolve('log', ['channel' => 'test-override']);

    expect($driver)->toBeInstanceOf(LogDriver::class);
});
