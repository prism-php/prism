<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Contracts\Span;
use Prism\Prism\Telemetry\ValueObjects\LogSpan;
use Prism\Prism\Telemetry\ValueObjects\SpanStatus;
use Prism\Prism\Telemetry\ValueObjects\TelemetryAttribute;
use Prism\Prism\Testing\LogFake;

beforeEach(function (): void {
    $this->logFake = LogFake::swap();
});

it('implements span interface', function (): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');

    expect($span)->toBeInstanceOf(Span::class);
});

it('stores name and start time correctly', function (): void {
    $startTime = microtime(true);
    $span = new LogSpan('test-span', $startTime, 'default', 'info');

    expect($span->getName())->toBe('test-span');
    expect($span->getStartTime())->toBe($startTime);
});

it('returns true for isRecording before ending', function (): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');

    expect($span->isRecording())->toBeTrue();
});

it('returns false for isRecording after ending', function (): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');

    $span->end();

    expect($span->isRecording())->toBeFalse();
});

it('calculates duration correctly', function (): void {
    $startTime = microtime(true);
    $span = new LogSpan('test-span', $startTime, 'default', 'info');

    expect($span->getDuration())->toBeNull();

    usleep(1000); // 1ms
    $endTime = microtime(true);
    $span->end($endTime);

    $duration = $span->getDuration();
    expect($duration)->toBeFloat();
    expect($duration)->toBeGreaterThan(0);
    expect($duration)->toBeLessThan(100); // Should be less than 100ms (duration is in milliseconds)
});

it('stores attributes correctly', function (): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');

    $result = $span->setAttribute('key', 'value');
    expect($result)->toBe($span);

    $result = $span->setAttributes(['key1' => 'value1', 'key2' => 'value2']);
    expect($result)->toBe($span);
});

it('accepts TelemetryAttribute enum as key', function (): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');

    $result = $span->setAttribute(TelemetryAttribute::ProviderName, 'openai');
    expect($result)->toBe($span);

    $result = $span->setAttribute(TelemetryAttribute::RequestTokensInput, 100);
    expect($result)->toBe($span);
});

it('stores status and description', function (): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');

    $result = $span->setStatus(SpanStatus::Ok);
    expect($result)->toBe($span);

    $result = $span->setStatus(SpanStatus::Error, 'Something went wrong');
    expect($result)->toBe($span);
});

it('logs span start on creation', function (): void {
    new LogSpan('test-span', microtime(true), 'default', 'info');

    $this->logFake->assertLogged('info', 'test-span');
});

it('logs span completion on end', function (): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');
    $this->logFake->clear(); // Clear the start log

    $span->end();

    $this->logFake->assertLogged('info', 'test-span');
});

it('includes attributes in completion log', function (): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');
    $span->setAttribute('test.attribute', 'test-value');
    $span->setAttribute(TelemetryAttribute::ProviderName, 'openai');
    $this->logFake->clear();

    $span->end();

    $logs = $this->logFake->logged('info', 'test-span');
    expect($logs)->toHaveCount(1);

    $log = $logs->first();
    expect($log['context'])->toHaveKey('test.attribute');
    expect($log['context']['test.attribute'])->toBe('test-value');
    expect($log['context'])->toHaveKey(TelemetryAttribute::ProviderName->value);
    expect($log['context'][TelemetryAttribute::ProviderName->value])->toBe('openai');
});

it('logs error level when status is error', function (): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');
    $span->setStatus(SpanStatus::Error, 'Something went wrong');
    $this->logFake->clear();

    $span->end();

    $this->logFake->assertLogged('error', 'test-span');
    $this->logFake->assertNotLogged('info', 'test-span');
});

it('logs correct level for different statuses', function (SpanStatus $status, string $expectedLevel): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');
    $span->setStatus($status);
    $this->logFake->clear();

    $span->end();

    $this->logFake->assertLogged($expectedLevel, 'test-span');
})->with([
    [SpanStatus::Ok, 'info'],
    [SpanStatus::Error, 'error'],
    [SpanStatus::Timeout, 'info'],
    [SpanStatus::Cancelled, 'info'],
]);

it('can only be ended once', function (): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');
    $this->logFake->clear();

    $span->end();
    $span->end(); // Second call should be ignored

    $this->logFake->assertLoggedCount(1, 'info', 'test-span');
});

it('returns self for fluent interface', function (): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');

    $result = $span
        ->setAttribute('key', 'value')
        ->setAttributes(['key1' => 'value1'])
        ->addEvent('event')
        ->setStatus(SpanStatus::Ok);

    expect($result)->toBe($span);
});

it('includes span id in all log entries', function (): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');
    $span->end();

    $logs = $this->logFake->getLogs();

    foreach ($logs as $log) {
        expect($log['context'])->toHaveKey('span.id');
        expect($log['context']['span.id'])->toBeString();
        expect(strlen((string) $log['context']['span.id']))->toBeGreaterThan(0);
    }
});

it('includes events in completion log', function (): void {
    $span = new LogSpan('test-span', microtime(true), 'default', 'info');
    $span->addEvent('processing.started');
    $span->addEvent('processing.checkpoint', ['progress' => 50]);
    $span->addEvent('processing.completed');
    $this->logFake->clear();

    $span->end();

    $logs = $this->logFake->logged('info', 'test-span');
    $log = $logs->first();

    expect($log['context'])->toHaveKey('span.events');
    expect($log['context']['span.events'])->toHaveCount(3);
    expect($log['context']['span.events'][0]['name'])->toBe('processing.started');
    expect($log['context']['span.events'][1]['name'])->toBe('processing.checkpoint');
    expect($log['context']['span.events'][1]['attributes']['progress'])->toBe(50);
    expect($log['context']['span.events'][2]['name'])->toBe('processing.completed');
});

it('handles duration conversion to milliseconds', function (): void {
    $startTime = microtime(true);
    $span = new LogSpan('test-span', $startTime, 'default', 'info');
    $this->logFake->clear();

    $endTime = $startTime + 0.001; // 1ms
    $span->end($endTime);

    $logs = $this->logFake->logged('info', 'test-span');
    $log = $logs->first();

    expect($log['context'])->toHaveKey('span.duration_ms');
    expect($log['context']['span.duration_ms'])->toBeFloat();
    expect($log['context']['span.duration_ms'])->toBeGreaterThanOrEqual(1.0);
});
