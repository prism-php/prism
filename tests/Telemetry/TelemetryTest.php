<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use Prism\Prism\Telemetry\Facades\Telemetry;
use Prism\Prism\Telemetry\TelemetryManager;
use Prism\Prism\Telemetry\ValueObjects\LogSpan;
use Prism\Prism\Telemetry\ValueObjects\NullSpan;
use Prism\Prism\Telemetry\ValueObjects\TelemetryAttribute;
use Prism\Prism\Testing\LogFake;

beforeEach(function (): void {
    $this->logFake = LogFake::swap();

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

it('resolves from container correctly', function (): void {
    $manager = app('prism-telemetry');

    expect($manager)->toBeInstanceOf(TelemetryManager::class);
});

it('is instance of Laravel Facade', function (): void {
    $reflection = new ReflectionClass(Telemetry::class);
    expect($reflection->isSubclassOf(Facade::class))->toBeTrue();
});

it('resolves to TelemetryManager instance', function (): void {
    $manager = Telemetry::getFacadeRoot();

    expect($manager)->toBeInstanceOf(TelemetryManager::class);
});

it('proxies startSpan to manager', function (): void {
    $span = Telemetry::startSpan('test-span');

    expect($span)->toBeInstanceOf(LogSpan::class);
    expect($span->getName())->toBe('test-span');
    expect($span->isRecording())->toBeTrue();
});

it('proxies startSpan with attributes to manager', function (): void {
    $attributes = [
        'test.attribute' => 'value',
        TelemetryAttribute::ProviderName->value => 'openai',
    ];

    $span = Telemetry::startSpan('test-span', $attributes);

    expect($span)->toBeInstanceOf(LogSpan::class);
    expect($span->getName())->toBe('test-span');
});

it('proxies startSpan with custom start time to manager', function (): void {
    $startTime = microtime(true) - 1.0;

    $span = Telemetry::startSpan('test-span', [], $startTime);

    expect($span)->toBeInstanceOf(LogSpan::class);
    expect($span->getStartTime())->toBe($startTime);
});

it('proxies span to manager', function (): void {
    $this->logFake->clear();

    $result = Telemetry::span('test-span', [], fn (): string => 'facade-result');

    expect($result)->toBe('facade-result');
    $this->logFake->assertLogged('info', 'test-span');
});

it('proxies span with attributes to manager', function (): void {
    $attributes = [
        'test.attribute' => 'value',
        TelemetryAttribute::RequestTokensInput->value => 100,
    ];
    $this->logFake->clear();

    $result = Telemetry::span('test-span', $attributes, fn (): string => 'result-with-attributes');

    expect($result)->toBe('result-with-attributes');

    $logs = $this->logFake->logged('info', 'test-span');
    $log = $logs->last(); // Get the end log
    expect($log['context'])->toHaveKey('test.attribute');
    expect($log['context']['test.attribute'])->toBe('value');
});

it('proxies enabled to manager', function (): void {
    config(['prism.telemetry.enabled' => true, 'prism.telemetry.default' => 'log']);
    expect(Telemetry::enabled())->toBeTrue();

    config(['prism.telemetry.enabled' => false]);
    expect(Telemetry::enabled())->toBeFalse();

    config(['prism.telemetry.enabled' => true, 'prism.telemetry.default' => 'null']);
    expect(Telemetry::enabled())->toBeTrue(); // Enabled is now independent of driver selection
});

it('proxies current to manager', function (): void {
    expect(Telemetry::current())->toBeNull();

    $span = Telemetry::startSpan('test-span');

    $currentSpan = Telemetry::withCurrentSpan($span, fn () => Telemetry::current());

    expect($currentSpan)->toBe($span);
    expect(Telemetry::current())->toBeNull();
});

it('supports method chaining through facade', function (): void {
    $span = Telemetry::startSpan('test-span')
        ->setAttribute('key', 'value')
        ->setAttribute(TelemetryAttribute::ProviderName, 'openai')
        ->setAttributes(['additional' => 'attributes']);

    expect($span)->toBeInstanceOf(LogSpan::class);
    expect($span->getName())->toBe('test-span');
});

it('handles exceptions through facade span method', function (): void {
    $this->logFake->clear();

    expect(function (): void {
        Telemetry::span('test-span', [], function (): void {
            throw new RuntimeException('Facade exception');
        });
    })->toThrow(RuntimeException::class, 'Facade exception');

    $this->logFake->assertLogged('error', 'test-span');
});

it('works with different telemetry configurations through facade', function (): void {
    // Test with log driver
    config(['prism.telemetry.default' => 'log']);
    $logSpan = Telemetry::startSpan('log-span');
    expect($logSpan)->toBeInstanceOf(LogSpan::class);

    // Test with null driver
    config(['prism.telemetry.default' => 'null']);
    $nullSpan = Telemetry::startSpan('null-span');
    expect($nullSpan)->toBeInstanceOf(NullSpan::class);
});

it('maintains span context through facade calls', function (): void {
    $this->logFake->clear();

    $result = Telemetry::span('outer-span', [], function () {
        $outerSpan = Telemetry::current();
        expect($outerSpan->getName())->toBe('outer-span');

        return Telemetry::span('inner-span', [], function (): string {
            $innerSpan = Telemetry::current();
            expect($innerSpan->getName())->toBe('inner-span');

            return 'nested-facade-result';
        });
    });

    expect($result)->toBe('nested-facade-result');
    expect(Telemetry::current())->toBeNull();
});

it('supports all manager methods through facade', function (): void {
    // Test startSpan
    $span = Telemetry::startSpan('facade-span');
    expect($span->getName())->toBe('facade-span');

    // Test span callback
    $result = Telemetry::span('callback-span', [], fn (): string => 'callback-result');
    expect($result)->toBe('callback-result');

    // Test enabled
    expect(Telemetry::enabled())->toBeBool();

    // Test current (should be null outside context)
    expect(Telemetry::current())->toBeNull();

    // Test withCurrentSpan
    $contextResult = Telemetry::withCurrentSpan($span, fn () => Telemetry::current());
    expect($contextResult)->toBe($span);
});

it('handles rapid successive calls through facade', function (): void {
    $this->logFake->clear();

    $results = [];
    for ($i = 0; $i < 5; $i++) {
        $results[] = Telemetry::span("span-{$i}", ['iteration' => $i], fn (): string => "result-{$i}");
    }

    expect($results)->toBe(['result-0', 'result-1', 'result-2', 'result-3', 'result-4']);
    // Check that all 5 spans were logged (2 logs each = start and end)
    for ($i = 0; $i < 5; $i++) {
        $this->logFake->assertLogged('info', "span-{$i}");
    }
});

it('preserves facade behavior when telemetry is disabled', function (): void {
    config(['prism.telemetry.enabled' => false]);

    // Should still work but use null implementations
    $span = Telemetry::startSpan('disabled-span');
    expect($span)->toBeInstanceOf(NullSpan::class);

    $result = Telemetry::span('disabled-callback', [], fn (): string => 'disabled-result');
    expect($result)->toBe('disabled-result');

    expect(Telemetry::enabled())->toBeFalse();
    expect(Telemetry::current())->toBeNull();
});

it('facade resolves consistently across multiple calls', function (): void {
    $manager1 = Telemetry::getFacadeRoot();
    $manager2 = Telemetry::getFacadeRoot();

    expect($manager1)->toBe($manager2);
    expect($manager1)->toBeInstanceOf(TelemetryManager::class);
});
