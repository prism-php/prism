<?php

declare(strict_types=1);

use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Drivers\LogDriver;
use Prism\Prism\Telemetry\Drivers\NullDriver;
use Prism\Prism\Telemetry\Drivers\OtlpDriver;
use Prism\Prism\Telemetry\SpanData;
use Prism\Prism\Telemetry\TelemetryManager;

it('resolves null driver by default', function (): void {
    $manager = new TelemetryManager(app());

    $driver = $manager->resolve('null');

    expect($driver)->toBeInstanceOf(NullDriver::class);
});

it('resolves log driver with configuration', function (): void {
    $manager = new TelemetryManager(app());

    $driver = $manager->resolve('log');

    expect($driver)->toBeInstanceOf(LogDriver::class);
});

it('resolves phoenix as otlp driver with openinference mapper', function (): void {
    $manager = new TelemetryManager(app());

    $driver = $manager->resolve('phoenix');

    expect($driver)->toBeInstanceOf(OtlpDriver::class);
    expect($driver->getDriver())->toBe('phoenix');
});

it('resolves custom otlp driver from config', function (): void {
    config([
        'prism.telemetry.drivers.langfuse' => [
            'driver' => 'otlp',
            'endpoint' => 'https://cloud.langfuse.com',
            'api_key' => 'test-key',
        ],
    ]);

    $manager = new TelemetryManager(app());

    $driver = $manager->resolve('langfuse');

    expect($driver)->toBeInstanceOf(OtlpDriver::class);
    expect($driver->getDriver())->toBe('langfuse');
});

it('throws exception for unsupported driver type', function (): void {
    config([
        'prism.telemetry.drivers.custom' => [
            'driver' => 'unsupported',
        ],
    ]);

    $manager = new TelemetryManager(app());

    $manager->resolve('custom');
})->throws(InvalidArgumentException::class, 'Telemetry driver [unsupported] is not supported.');

it('uses configuration from config file for log driver', function (): void {
    config([
        'prism.telemetry.drivers.log' => [
            'driver' => 'log',
            'channel' => 'custom-channel',
        ],
    ]);

    $manager = new TelemetryManager(app());
    $driver = $manager->resolve('log');

    expect($driver)->toBeInstanceOf(LogDriver::class);
});

it('resolves custom driver via class', function (): void {
    $customDriver = new class implements TelemetryDriver
    {
        public function recordSpan(SpanData $span): void {}

        public function shutdown(): void {}
    };

    $factoryClass = new class($customDriver)
    {
        public function __construct(private readonly TelemetryDriver $driver) {}

        public function __invoke($app, $config): TelemetryDriver
        {
            return $this->driver;
        }
    };

    app()->instance('custom-factory', $factoryClass);

    config([
        'prism.telemetry.drivers.my-custom' => [
            'driver' => 'custom',
            'via' => 'custom-factory',
        ],
    ]);

    $manager = new TelemetryManager(app());
    $driver = $manager->resolve('my-custom');

    expect($driver)->toBe($customDriver);
});

it('resolves custom driver via closure', function (): void {
    $customDriver = new class implements TelemetryDriver
    {
        public function recordSpan(SpanData $span): void {}

        public function shutdown(): void {}
    };

    config([
        'prism.telemetry.drivers.closure-custom' => [
            'driver' => 'custom',
            'via' => fn ($app, $config): object => $customDriver,
        ],
    ]);

    $manager = new TelemetryManager(app());
    $driver = $manager->resolve('closure-custom');

    expect($driver)->toBe($customDriver);
});

it('throws exception when custom driver missing via key', function (): void {
    config([
        'prism.telemetry.drivers.bad-custom' => [
            'driver' => 'custom',
        ],
    ]);

    $manager = new TelemetryManager(app());
    $manager->resolve('bad-custom');
})->throws(InvalidArgumentException::class, "Custom telemetry driver [bad-custom] requires a 'via' configuration option.");

it('passes config to custom driver factory', function (): void {
    $receivedConfig = null;

    config([
        'prism.telemetry.drivers.config-test' => [
            'driver' => 'custom',
            'via' => function ($app, $config) use (&$receivedConfig): \Prism\Prism\Contracts\TelemetryDriver {
                $receivedConfig = $config;

                return new class implements TelemetryDriver
                {
                    public function recordSpan(SpanData $span): void {}

                    public function shutdown(): void {}
                };
            },
            'custom_option' => 'test-value',
        ],
    ]);

    $manager = new TelemetryManager(app());
    $manager->resolve('config-test');

    expect($receivedConfig)->toHaveKey('custom_option', 'test-value');
});
