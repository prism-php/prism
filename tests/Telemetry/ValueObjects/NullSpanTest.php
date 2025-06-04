<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Contracts\Span;
use Prism\Prism\Telemetry\ValueObjects\NullSpan;
use Prism\Prism\Telemetry\ValueObjects\SpanStatus;
use Prism\Prism\Telemetry\ValueObjects\TelemetryAttribute;

it('implements span interface', function (): void {
    $span = new NullSpan('test-span');

    expect($span)->toBeInstanceOf(Span::class);
});

it('stores name and start time correctly', function (): void {
    $startTime = microtime(true);
    $span = new NullSpan('test-span', $startTime);

    expect($span->getName())->toBe('test-span');
    expect($span->getStartTime())->toBe($startTime);
});

it('returns false for isRecording', function (): void {
    $span = new NullSpan('test-span');

    expect($span->isRecording())->toBeFalse();
});

it('returns null for duration', function (): void {
    $span = new NullSpan('test-span');

    expect($span->getDuration())->toBeNull();

    // Even after ending
    $span->end();
    expect($span->getDuration())->toBeNull();
});

it('allows setting attributes without side effects', function (): void {
    $span = new NullSpan('test-span');

    $result = $span->setAttribute('key', 'value');
    expect($result)->toBe($span);

    $result = $span->setAttributes(['key1' => 'value1', 'key2' => 'value2']);
    expect($result)->toBe($span);
});

it('allows setting attributes with telemetry attribute enum', function (): void {
    $span = new NullSpan('test-span');

    $result = $span->setAttribute(TelemetryAttribute::ProviderName, 'openai');
    expect($result)->toBe($span);
});

it('allows adding events without side effects', function (): void {
    $span = new NullSpan('test-span');

    $result = $span->addEvent('test-event');
    expect($result)->toBe($span);

    $result = $span->addEvent('test-event-with-attributes', ['key' => 'value']);
    expect($result)->toBe($span);
});

it('allows setting status without side effects', function (): void {
    $span = new NullSpan('test-span');

    $result = $span->setStatus(SpanStatus::Ok);
    expect($result)->toBe($span);

    $result = $span->setStatus(SpanStatus::Error, 'test error');
    expect($result)->toBe($span);
});

it('allows ending span multiple times safely', function (): void {
    $span = new NullSpan('test-span');

    // Should not throw any exceptions
    $span->end();
    $span->end();
    $span->end(microtime(true));

    expect($span->getDuration())->toBeNull();
});

it('returns self for fluent interface', function (): void {
    $span = new NullSpan('test-span');

    $result = $span
        ->setAttribute('key', 'value')
        ->setAttributes(['key1' => 'value1'])
        ->addEvent('event')
        ->setStatus(SpanStatus::Ok);

    expect($result)->toBe($span);
});

it('handles all span methods without errors', function (): void {
    $span = new NullSpan('test-span');

    // Chain all methods to ensure no errors
    $span
        ->setAttribute(TelemetryAttribute::ProviderName, 'openai')
        ->setAttribute('custom.attribute', 'value')
        ->setAttributes([
            'request.tokens' => 100,
            'response.tokens' => 50,
        ])
        ->addEvent('processing.started')
        ->addEvent('processing.completed', ['duration' => 0.5])
        ->setStatus(SpanStatus::Ok, 'Success')
        ->end();

    expect($span->isRecording())->toBeFalse();
    expect($span->getDuration())->toBeNull();
});
