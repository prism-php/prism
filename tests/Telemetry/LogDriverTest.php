<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Prism\Prism\Telemetry\LogDriver;

it('can create a span with log driver', function (): void {
    $driver = new LogDriver('test', true);

    // Mock the Log facade to capture log entries
    Log::shouldReceive('channel')
        ->with('test')
        ->twice()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->twice()
        ->withArgs(function ($message, $context): bool {
            static $callCount = 0;
            $callCount++;

            if ($callCount === 1) {
                // First call should be span start
                return str_contains($message, 'span started') &&
                       $context['prism.telemetry.event'] === 'span.start' &&
                       $context['prism.telemetry.span_name'] === 'test.span' &&
                       isset($context['prism.telemetry.span_id']);
            }
            // Second call should be span end
            return str_contains($message, 'span completed') &&
                   $context['prism.telemetry.event'] === 'span.end' &&
                   $context['prism.telemetry.span_name'] === 'test.span' &&
                   $context['prism.telemetry.status'] === 'success' &&
                   isset($context['prism.telemetry.duration_ms']);
        });

    $result = $driver->span('test.span', [
        'test.attribute' => 'test.value',
    ], fn(): string => 'test result');

    expect($result)->toBe('test result');
});

it('can create a child span with log driver', function (): void {
    $driver = new LogDriver('test', true);

    // Mock the Log facade to capture log entries
    Log::shouldReceive('channel')
        ->with('test')
        ->twice()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->twice()
        ->withArgs(function ($message, array $context): bool {
            static $callCount = 0;
            $callCount++;

            if ($callCount === 1) {
                // First call should be span start with child marker
                return str_contains($message, 'span started') &&
                       $context['prism.telemetry.event'] === 'span.start' &&
                       $context['prism.telemetry.span_name'] === 'test.child.span' &&
                       $context['prism.telemetry.span_type'] === 'child';
            }
            // Second call should be span end
            return str_contains($message, 'span completed') &&
                   $context['prism.telemetry.event'] === 'span.end' &&
                   $context['prism.telemetry.span_name'] === 'test.child.span' &&
                   $context['prism.telemetry.status'] === 'success';
        });

    $result = $driver->childSpan('test.child.span', [
        'test.attribute' => 'test.value',
    ], fn(): string => 'child result');

    expect($result)->toBe('child result');
});

it('handles exceptions in spans', function (): void {
    $driver = new LogDriver('test', true);

    // Mock the Log facade to capture log entries
    Log::shouldReceive('channel')
        ->with('test')
        ->twice()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->twice()
        ->withArgs(function ($message, array $context): bool {
            static $callCount = 0;
            $callCount++;

            if ($callCount === 1) {
                // First call should be span start
                return str_contains($message, 'span started') &&
                       $context['prism.telemetry.event'] === 'span.start';
            }
            // Second call should be span end with error
            return str_contains($message, 'span failed') &&
                   $context['prism.telemetry.event'] === 'span.end' &&
                   $context['prism.telemetry.status'] === 'error' &&
                   $context['prism.telemetry.error.class'] === 'Exception' &&
                   $context['prism.telemetry.error.message'] === 'Test exception';
        });

    expect(function () use ($driver): void {
        $driver->span('test.error.span', [], function (): void {
            throw new Exception('Test exception');
        });
    })->toThrow(Exception::class, 'Test exception');
});

it('respects enabled flag', function (): void {
    $driver = new LogDriver('test', false);

    expect($driver->enabled())->toBeFalse();

    // No log calls should be made when disabled
    Log::shouldReceive('channel')->never();
    Log::shouldReceive('info')->never();

    $result = $driver->span('test.span', [], fn(): string => 'disabled result');

    expect($result)->toBe('disabled result');
});
