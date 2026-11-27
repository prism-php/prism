<?php

declare(strict_types=1);

use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Support\ToolResultClassifier;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\ToolCall;

afterEach(function (): void {
    Prism::classifyToolResultsUsing(null);
});

it('keeps appended telemetry failure fields compatible with existing constructions', function (): void {
    $context = new TelemetryContext('trace', TelemetryOperation::Text, 'openai', 'm', microtime(true));
    $event = new ToolInvoked($context, 'tool', 'call', 1.0);
    expect($event->failed)->toBeFalse()->and($event->failure)->toBeNull()
        ->and($event->toolCall)->toBeNull()->and($event->toolResult)->toBeNull();
});

it('registers and clears a process-wide host result classifier', function (): void {
    $call = new ToolCall('call', 'tool', []);
    Prism::classifyToolResultsUsing(fn (ToolCall $actual, mixed $result): ?string => $actual === $call && $result === 'original' ? 'service.unavailable' : null);
    expect(ToolResultClassifier::failure($call, 'original'))->toBe('service.unavailable')
        ->and(ToolResultClassifier::failure($call, 'other'))->toBeNull();
    Prism::classifyToolResultsUsing(null);
    expect(ToolResultClassifier::failure($call, 'original'))->toBeNull();
});

it('bounds and sanitizes classifier kinds without copying content', function (mixed $kind): void {
    Prism::classifyToolResultsUsing(fn (): mixed => $kind);
    expect(ToolResultClassifier::failure(new ToolCall('call', 'tool', []), 'secret result'))->toBe('classified_failure');
})->with([
    'empty' => [''],
    'long' => [str_repeat('a', 65)],
    'result-like' => ['{"error":"customer secret"}'],
    'newline' => ["coarse\n"],
    'non-string' => [42],
]);

it('accepts bounded classifier kinds and handles classifier exceptions without content', function (): void {
    $call = new ToolCall('call', 'tool', []);
    Prism::classifyToolResultsUsing(fn (): string => str_repeat('a', 64));
    expect(ToolResultClassifier::failure($call, 'secret'))->toBe(str_repeat('a', 64));
    Prism::classifyToolResultsUsing(fn () => throw new RuntimeException('credential-secret'));
    expect(ToolResultClassifier::failure($call, 'secret'))->toBe('classifier_error');
});
