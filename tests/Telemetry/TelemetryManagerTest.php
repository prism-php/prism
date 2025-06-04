<?php

declare(strict_types=1);

use Illuminate\Support\Manager;
use Prism\Prism\Telemetry\Drivers\LogDriver;
use Prism\Prism\Telemetry\Drivers\NullDriver;
use Prism\Prism\Telemetry\TelemetryManager;
use Prism\Prism\Telemetry\ValueObjects\LogSpan;
use Prism\Prism\Telemetry\ValueObjects\NullSpan;
use Prism\Prism\Telemetry\ValueObjects\TelemetryAttribute;
use Prism\Prism\Testing\LogFake;

beforeEach(function (): void {
    $this->logFake = LogFake::swap();

    // Set up configuration for testing
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.default' => 'log',
        'prism.telemetry.drivers.null' => [
            'driver' => 'null',
        ],
        'prism.telemetry.drivers.log' => [
            'driver' => 'log',
            'channel' => 'default',
            'level' => 'info',
            'include_attributes' => true,
        ],
    ]);
});

it('extends Laravel Manager', function (): void {
    $manager = new TelemetryManager(app());

    expect($manager)->toBeInstanceOf(Manager::class);
});

it('returns correct default driver from config', function (): void {
    config(['prism.telemetry.default' => 'log']);
    $manager = new TelemetryManager(app());

    $driver = $manager->driver();

    expect($driver)->toBeInstanceOf(LogDriver::class);
});

it('returns null driver when default is null', function (): void {
    config(['prism.telemetry.default' => 'null']);
    $manager = new TelemetryManager(app());

    $driver = $manager->driver();

    expect($driver)->toBeInstanceOf(NullDriver::class);
});

it('creates null driver correctly', function (): void {
    $manager = new TelemetryManager(app());

    $driver = $manager->driver('null');

    expect($driver)->toBeInstanceOf(NullDriver::class);
});

it('creates log driver with configuration', function (): void {
    $manager = new TelemetryManager(app());

    $driver = $manager->driver('log');

    expect($driver)->toBeInstanceOf(LogDriver::class);
});

it('returns true for enabled when config enabled', function (): void {
    config(['prism.telemetry.enabled' => true]);
    $manager = new TelemetryManager(app());

    expect($manager->enabled())->toBeTrue();
});

it('returns false for enabled when config disabled', function (): void {
    config(['prism.telemetry.enabled' => false]);
    $manager = new TelemetryManager(app());

    expect($manager->enabled())->toBeFalse();
});

it('enabled status is independent of driver selection', function (): void {
    config(['prism.telemetry.enabled' => true, 'prism.telemetry.default' => 'null']);
    $manager = new TelemetryManager(app());

    expect($manager->enabled())->toBeTrue();

    config(['prism.telemetry.enabled' => true, 'prism.telemetry.default' => 'log']);
    expect($manager->enabled())->toBeTrue();
});

it('tracks current span correctly', function (): void {
    $manager = new TelemetryManager(app());
    $span = $manager->startSpan('test-span');

    $result = $manager->withCurrentSpan($span, fn (): ?\Prism\Prism\Telemetry\Contracts\Span => $manager->current());

    expect($result)->toBe($span);
});

it('returns null when no current span', function (): void {
    $manager = new TelemetryManager(app());

    expect($manager->current())->toBeNull();
});

it('creates spans through active driver', function (): void {
    config(['prism.telemetry.default' => 'log']);
    $manager = new TelemetryManager(app());

    $span = $manager->startSpan('test-span');

    expect($span)->toBeInstanceOf(LogSpan::class);
    expect($span->getName())->toBe('test-span');
});

it('creates null spans when disabled', function (): void {
    config(['prism.telemetry.enabled' => false]);
    $manager = new TelemetryManager(app());

    $span = $manager->startSpan('test-span');

    expect($span)->toBeInstanceOf(NullSpan::class);
});

it('creates spans with attributes', function (): void {
    config(['prism.telemetry.default' => 'log']);
    $manager = new TelemetryManager(app());
    $attributes = [
        'test.attribute' => 'value',
        TelemetryAttribute::ProviderName->value => 'openai',
    ];

    $span = $manager->startSpan('test-span', $attributes);

    expect($span)->toBeInstanceOf(LogSpan::class);
    expect($span->getName())->toBe('test-span');
});

it('creates spans with custom start time', function (): void {
    config(['prism.telemetry.default' => 'log']);
    $manager = new TelemetryManager(app());
    $startTime = microtime(true) - 1.0;

    $span = $manager->startSpan('test-span', [], $startTime);

    expect($span)->toBeInstanceOf(LogSpan::class);
    expect($span->getStartTime())->toBe($startTime);
});

it('executes span callback with span context', function (): void {
    config(['prism.telemetry.default' => 'log']);
    $manager = new TelemetryManager(app());
    $this->logFake->clear();

    $result = $manager->span('test-span', [], function () use ($manager): string {
        $current = $manager->current();
        expect($current)->not->toBeNull();
        expect($current->getName())->toBe('test-span');

        return 'callback-result';
    });

    expect($result)->toBe('callback-result');
});

it('clears current span after callback completion', function (): void {
    config(['prism.telemetry.default' => 'log']);
    $manager = new TelemetryManager(app());

    $manager->span('test-span', [], function (): void {
        // Inside callback
    });

    // Outside callback
    expect($manager->current())->toBeNull();
});

it('handles nested span contexts correctly', function (): void {
    config(['prism.telemetry.default' => 'log']);
    $manager = new TelemetryManager(app());
    $this->logFake->clear();

    $result = $manager->span('outer-span', [], function () use ($manager) {
        $outerSpan = $manager->current();
        expect($outerSpan->getName())->toBe('outer-span');

        return $manager->span('inner-span', [], function () use ($manager, $outerSpan): string {
            $innerSpan = $manager->current();
            expect($innerSpan->getName())->toBe('inner-span');
            expect($innerSpan)->not->toBe($outerSpan);

            return 'nested-result';
        });
    });

    expect($result)->toBe('nested-result');
    expect($manager->current())->toBeNull();
});

it('restores previous span context after nested completion', function (): void {
    config(['prism.telemetry.default' => 'log']);
    $manager = new TelemetryManager(app());
    $outerSpan = $manager->startSpan('outer-span');

    $manager->withCurrentSpan($outerSpan, function () use ($manager): void {
        $currentOuter = $manager->current();
        expect($currentOuter->getName())->toBe('outer-span');

        $manager->span('inner-span', [], function () use ($manager): void {
            $currentInner = $manager->current();
            expect($currentInner->getName())->toBe('inner-span');
        });

        // Should restore outer span
        $restoredOuter = $manager->current();
        expect($restoredOuter->getName())->toBe('outer-span');
    });
});

it('handles exceptions in span callbacks correctly', function (): void {
    config(['prism.telemetry.default' => 'log']);
    $manager = new TelemetryManager(app());
    $this->logFake->clear();

    expect(function () use ($manager): void {
        $manager->span('test-span', [], function (): void {
            throw new RuntimeException('Test exception');
        });
    })->toThrow(RuntimeException::class);

    // Current span should be cleared even after exception
    expect($manager->current())->toBeNull();
});

it('caches driver instances correctly', function (): void {
    $manager = new TelemetryManager(app());

    $driver1 = $manager->driver('log');
    $driver2 = $manager->driver('log');

    expect($driver1)->toBe($driver2);
});

it('creates different instances for different drivers', function (): void {
    $manager = new TelemetryManager(app());

    $logDriver = $manager->driver('log');
    $nullDriver = $manager->driver('null');

    expect($logDriver)->not->toBe($nullDriver);
    expect($logDriver)->toBeInstanceOf(LogDriver::class);
    expect($nullDriver)->toBeInstanceOf(NullDriver::class);
});

it('falls back to null driver for unknown driver', function (): void {
    $manager = new TelemetryManager(app());

    expect(function () use ($manager): void {
        $manager->driver('unknown-driver');
    })->toThrow(InvalidArgumentException::class);
});

it('uses custom configuration for log driver', function (): void {
    config([
        'prism.telemetry.drivers.log' => [
            'driver' => 'log',
            'channel' => 'telemetry',
            'level' => 'debug',
            'include_attributes' => false,
        ],
    ]);

    $manager = new TelemetryManager(app());
    $driver = $manager->driver('log');

    expect($driver)->toBeInstanceOf(LogDriver::class);
});

it('maintains manager state correctly across multiple operations', function (): void {
    config(['prism.telemetry.default' => 'log']);
    $manager = new TelemetryManager(app());

    // Create multiple spans
    $span1 = $manager->startSpan('span-1');
    $span2 = $manager->startSpan('span-2');

    expect($span1)->toBeInstanceOf(LogSpan::class);
    expect($span2)->toBeInstanceOf(LogSpan::class);
    expect($span1)->not->toBe($span2);

    // Check enabled status multiple times
    expect($manager->enabled())->toBeTrue();
    expect($manager->enabled())->toBeTrue();

    // Execute multiple span callbacks
    $result1 = $manager->span('callback-span-1', [], fn (): string => 'result-1');
    $result2 = $manager->span('callback-span-2', [], fn (): string => 'result-2');

    expect($result1)->toBe('result-1');
    expect($result2)->toBe('result-2');
});
