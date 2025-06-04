<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Facades\Telemetry;
use Prism\Prism\Telemetry\ValueObjects\SpanStatus;
use Prism\Prism\Telemetry\ValueObjects\TelemetryAttribute;
use Prism\Prism\Testing\LogFake;

beforeEach(function (): void {
    $this->logFake = LogFake::swap();
});

describe('Basic end-to-end functionality', function (): void {
    beforeEach(function (): void {
        config([
            'prism.telemetry.enabled' => true,
            'prism.telemetry.default' => 'log',
            'prism.telemetry.drivers.log' => [
                'driver' => 'log',
                'channel' => 'default',
                'level' => 'info',
                'include_attributes' => true,
            ],
        ]);
    });

    it('records complete span lifecycle with log driver', function (): void {
        $this->logFake->clear();

        $span = Telemetry::startSpan('integration-test-span');
        $span->setAttribute('test.phase', 'start');
        $span->addEvent('processing.started');

        usleep(1000); // Simulate some work

        $span->setAttribute('test.phase', 'middle');
        $span->addEvent('processing.halfway', ['progress' => 50]);

        usleep(1000); // More work

        $span->setAttribute('test.phase', 'end');
        $span->setStatus(SpanStatus::Ok, 'Successfully completed');
        $span->addEvent('processing.completed');
        $span->end();

        // Verify start and completion logs
        $this->logFake->assertLogged('info', 'Span started');
        $this->logFake->assertLogged('info', 'Span completed');

        // Verify completion log includes all data
        $completionLogs = $this->logFake->logged('info', 'Span completed');
        $log = $completionLogs->first();

        expect($log['context'])->toHaveKey('span.name', 'integration-test-span');
        expect($log['context'])->toHaveKey('span.status', SpanStatus::Ok->value);
        expect($log['context'])->toHaveKey('span.status_description', 'Successfully completed');
        expect($log['context'])->toHaveKey('span.duration_ms');
        expect($log['context']['span.duration_ms'])->toBeGreaterThan(1.0);

        expect($log['context']['attributes'])->toHaveKey('test.phase', 'end');
        expect($log['context']['span.events'])->toHaveCount(3);
        expect($log['context']['span.events'][0]['name'])->toBe('processing.started');
        expect($log['context']['span.events'][1]['name'])->toBe('processing.halfway');
        expect($log['context']['span.events'][2]['name'])->toBe('processing.completed');
    });

    it('maintains span context during callback execution', function (): void {
        $this->logFake->clear();
        $contextSpans = [];

        $result = Telemetry::span('context-test-span', ['operation' => 'context-test'], function () use (&$contextSpans) {
            $contextSpans[] = Telemetry::current();

            // Nested span
            return Telemetry::span('nested-span', [], function () use (&$contextSpans): string {
                $contextSpans[] = Telemetry::current();

                return 'nested-result';
            });
        });

        expect($result)->toBe('nested-result');
        expect($contextSpans)->toHaveCount(2);
        expect($contextSpans[0]->getName())->toBe('context-test-span');
        expect($contextSpans[1]->getName())->toBe('nested-span');
        expect(Telemetry::current())->toBeNull();

        $this->logFake->assertLoggedCount(2, 'info', 'Span completed');
    });

    it('handles exceptions while preserving telemetry', function (): void {
        $this->logFake->clear();

        expect(function (): void {
            Telemetry::span('exception-test-span', ['will_fail' => true], function (): void {
                Telemetry::current()->addEvent('about.to.fail');
                throw new RuntimeException('Integration test exception');
            });
        })->toThrow(RuntimeException::class, 'Integration test exception');

        $this->logFake->assertLogged('error', 'Span completed');

        $errorLogs = $this->logFake->logged('error', 'Span completed');
        $log = $errorLogs->first();

        expect($log['context']['span.status'])->toBe(SpanStatus::Error->value);
        expect($log['context']['span.status_description'])->toBe('Integration test exception');
        expect($log['context']['attributes']['will_fail'])->toBeTrue();
        if (isset($log['context']['span.events'])) {
            expect($log['context']['span.events'][0]['name'])->toBe('about.to.fail');
        }

        // Context should be cleaned up
        expect(Telemetry::current())->toBeNull();
    });
});

describe('Configuration integration', function (): void {
    it('respects telemetry enabled/disabled config', function (): void {
        // Enabled
        config(['prism.telemetry.enabled' => true, 'prism.telemetry.default' => 'log']);
        expect(Telemetry::enabled())->toBeTrue();

        $span = Telemetry::startSpan('enabled-span');
        expect($span->isRecording())->toBeTrue();

        // Disabled
        config(['prism.telemetry.enabled' => false]);
        expect(Telemetry::enabled())->toBeFalse();

        $span = Telemetry::startSpan('disabled-span');
        expect($span->isRecording())->toBeFalse();
    });

    it('switches between null and log drivers correctly', function (): void {
        // Log driver
        config([
            'prism.telemetry.enabled' => true,
            'prism.telemetry.default' => 'log',
        ]);

        $logSpan = Telemetry::startSpan('log-driver-span');
        expect($logSpan->isRecording())->toBeTrue();

        // Null driver
        config(['prism.telemetry.default' => 'null']);

        $nullSpan = Telemetry::startSpan('null-driver-span');
        expect($nullSpan->isRecording())->toBeFalse();
    });

    it('uses environment variables for configuration', function (): void {
        // Set environment variables
        putenv('PRISM_TELEMETRY_ENABLED=true');
        putenv('PRISM_TELEMETRY_DRIVER=log');
        putenv('PRISM_TELEMETRY_LOG_LEVEL=debug');

        config([
            'prism.telemetry.enabled' => env('PRISM_TELEMETRY_ENABLED', false),
            'prism.telemetry.default' => env('PRISM_TELEMETRY_DRIVER', 'null'),
            'prism.telemetry.drivers.log.level' => env('PRISM_TELEMETRY_LOG_LEVEL', 'info'),
        ]);

        expect(Telemetry::enabled())->toBeTrue();

        // Clean up
        putenv('PRISM_TELEMETRY_ENABLED');
        putenv('PRISM_TELEMETRY_DRIVER');
        putenv('PRISM_TELEMETRY_LOG_LEVEL');
    });
});

describe('Provider integration (basic)', function (): void {
    beforeEach(function (): void {
        config([
            'prism.telemetry.enabled' => true,
            'prism.telemetry.default' => 'log',
        ]);
    });

    it('can wrap provider requests with telemetry', function (): void {
        $this->logFake->clear();

        // Simulate a provider request
        $result = Telemetry::span('provider.request', [
            TelemetryAttribute::ProviderName->value => 'openai',
            TelemetryAttribute::ProviderModel->value => 'gpt-4',
            TelemetryAttribute::RequestType->value => 'text_generation',
        ], function (): array {
            // Simulate provider work
            usleep(2000); // 2ms

            return [
                'text' => 'Generated response',
                'tokens' => ['prompt' => 100, 'completion' => 50],
            ];
        });

        expect($result['text'])->toBe('Generated response');
        expect($result['tokens']['prompt'])->toBe(100);

        $logs = $this->logFake->logged('info', 'Span completed');
        $log = $logs->first();

        expect($log['context']['attributes'][TelemetryAttribute::ProviderName->value])->toBe('openai');
        expect($log['context']['attributes'][TelemetryAttribute::ProviderModel->value])->toBe('gpt-4');
        expect($log['context']['attributes'][TelemetryAttribute::RequestType->value])->toBe('text_generation');
    });

    it('handles provider exceptions correctly', function (): void {
        $this->logFake->clear();

        expect(function (): void {
            Telemetry::span('provider.request', [
                TelemetryAttribute::ProviderName->value => 'openai',
            ], function (): void {
                Telemetry::current()->addEvent('request.started');
                throw new Exception('Provider API error', 500);
            });
        })->toThrow(Exception::class, 'Provider API error');

        $logs = $this->logFake->logged('error', 'Span completed');
        $log = $logs->first();

        expect($log['context']['span.status'])->toBe(SpanStatus::Error->value);
        expect($log['context']['span.status_description'])->toBe('Provider API error');
        expect($log['context']['attributes'][TelemetryAttribute::ProviderName->value])->toBe('openai');
        if (isset($log['context']['span.events'])) {
            expect($log['context']['span.events'][0]['name'])->toBe('request.started');
        }
    });
});

describe('Tool integration (basic)', function (): void {
    beforeEach(function (): void {
        config([
            'prism.telemetry.enabled' => true,
            'prism.telemetry.default' => 'log',
        ]);
    });

    it('can wrap tool execution with telemetry', function (): void {
        $this->logFake->clear();

        $result = Telemetry::span('tool.execution', [
            TelemetryAttribute::ToolName->value => 'calculator',
        ], fn(): array =>
            // Simulate tool work
            ['result' => 42, 'operation' => 'multiply', 'operands' => [6, 7]]);

        expect($result['result'])->toBe(42);

        $logs = $this->logFake->logged('info', 'Span completed');
        $log = $logs->first();

        expect($log['context']['attributes'][TelemetryAttribute::ToolName->value])->toBe('calculator');
    });

    it('records tool success and failure', function (): void {
        $this->logFake->clear();

        // Success case
        Telemetry::span('tool.success', [TelemetryAttribute::ToolName->value => 'calculator'], function (): string {
            Telemetry::current()->setAttribute(TelemetryAttribute::ToolSuccess, true);

            return 'success';
        });

        // Failure case
        expect(function (): void {
            Telemetry::span('tool.failure', [TelemetryAttribute::ToolName->value => 'calculator'], function (): void {
                Telemetry::current()->setAttribute(TelemetryAttribute::ToolSuccess, false);
                throw new RuntimeException('Tool execution failed');
            });
        })->toThrow(RuntimeException::class);

        $successLogs = $this->logFake->logged('info', 'Span completed');
        $failureLogs = $this->logFake->logged('error', 'Span completed');

        expect($successLogs->count())->toBe(1);
        expect($failureLogs->count())->toBe(1);

        $successLog = $successLogs->first();
        $failureLog = $failureLogs->first();

        if (isset($successLog['context']['attributes'][TelemetryAttribute::ToolSuccess->value])) {
            expect($successLog['context']['attributes'][TelemetryAttribute::ToolSuccess->value])->toBeTrue();
        }
        if (isset($failureLog['context']['attributes'][TelemetryAttribute::ToolSuccess->value])) {
            expect($failureLog['context']['attributes'][TelemetryAttribute::ToolSuccess->value])->toBeFalse();
        }
    });
});

describe('Facade integration', function (): void {
    beforeEach(function (): void {
        config([
            'prism.telemetry.enabled' => true,
            'prism.telemetry.default' => 'log',
        ]);
    });

    it('works through facade interface', function (): void {
        $this->logFake->clear();

        $result = Telemetry::span('facade.test', ['source' => 'facade'], function (): string {
            expect(Telemetry::enabled())->toBeTrue();
            expect(Telemetry::current())->not->toBeNull();
            expect(Telemetry::current()->getName())->toBe('facade.test');

            return 'facade-result';
        });

        expect($result)->toBe('facade-result');
        expect(Telemetry::current())->toBeNull();

        $this->logFake->assertLogged('info', 'Span completed');
    });

    it('maintains manager state through facade', function (): void {
        $this->logFake->clear();

        // Multiple operations through facade
        $span1 = Telemetry::startSpan('manual-span-1');
        $span2 = Telemetry::startSpan('manual-span-2');

        expect($span1->getName())->toBe('manual-span-1');
        expect($span2->getName())->toBe('manual-span-2');

        $callbackResult = Telemetry::span('callback-span', [], fn (): string => 'callback-done');
        expect($callbackResult)->toBe('callback-done');

        $span1->end();
        $span2->end();

        $this->logFake->assertLoggedCount(3, 'info', 'Span completed');
    });
});

describe('Performance and edge cases', function (): void {
    it('handles rapid span creation and completion', function (): void {
        config([
            'prism.telemetry.enabled' => true,
            'prism.telemetry.default' => 'log',
        ]);
        $this->logFake->clear();

        $start = microtime(true);

        for ($i = 0; $i < 100; $i++) {
            Telemetry::span("rapid-span-{$i}", ['iteration' => $i], fn(): string => "result-{$i}");
        }

        $duration = microtime(true) - $start;

        // Should complete reasonably quickly (less than 100ms for 100 spans)
        expect($duration)->toBeLessThan(0.1);
        $this->logFake->assertLoggedCount(100, 'info', 'Span completed');
    });

    it('handles deeply nested spans correctly', function (): void {
        config([
            'prism.telemetry.enabled' => true,
            'prism.telemetry.default' => 'log',
        ]);
        $this->logFake->clear();

        $depth = 10;
        $result = null;

        // Create deeply nested spans
        $callback = function ($level) use (&$callback, $depth) {
            if ($level >= $depth) {
                return "depth-{$level}";
            }

            return Telemetry::span("nested-span-{$level}", ['level' => $level], fn(): mixed => $callback($level + 1));
        };

        $result = Telemetry::span('root-span', [], fn() => $callback(0));

        expect($result)->toBe("depth-{$depth}");
        $this->logFake->assertLoggedCount($depth + 1, 'info', 'Span completed');
    });
});
