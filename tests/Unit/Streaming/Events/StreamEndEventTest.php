<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\ValueObjects\Usage;

it('constructs with required parameters', function (): void {
    $event = new StreamEndEvent(
        id: 'event-123',
        timestamp: 1640995200,
        finishReason: FinishReason::Stop
    );

    expect($event->id)->toBe('event-123')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->finishReason)->toBe(FinishReason::Stop)
        ->and($event->usage)->toBeNull();
});

it('constructs with usage information', function (): void {
    $usage = new Usage(
        promptTokens: 100,
        completionTokens: 50,
        cacheWriteInputTokens: 25,
        cacheReadInputTokens: 10,
        thoughtTokens: 5
    );

    $event = new StreamEndEvent(
        id: 'event-123',
        timestamp: 1640995200,
        finishReason: FinishReason::Length,
        usage: $usage
    );

    expect($event->usage)->toBe($usage);
});

it('returns correct stream event type', function (): void {
    $event = new StreamEndEvent(
        id: 'event-123',
        timestamp: 1640995200,
        finishReason: FinishReason::Stop
    );

    expect($event->type())->toBe(StreamEventType::StreamEnd);
});

it('converts to array without usage', function (): void {
    $event = new StreamEndEvent(
        id: 'event-123',
        timestamp: 1640995200,
        finishReason: FinishReason::ContentFilter
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'finish_reason' => 'ContentFilter',
        'usage' => null,
        'citations' => null,
    ]);
});

it('converts to array with complete usage information', function (): void {
    $usage = new Usage(
        promptTokens: 100,
        completionTokens: 50,
        cacheWriteInputTokens: 25,
        cacheReadInputTokens: 10,
        thoughtTokens: 5
    );

    $event = new StreamEndEvent(
        id: 'event-123',
        timestamp: 1640995200,
        finishReason: FinishReason::ToolCalls,
        usage: $usage
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'finish_reason' => 'ToolCalls',
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'cache_write_input_tokens' => 25,
            'cache_read_input_tokens' => 10,
            'thought_tokens' => 5,
        ],
        'citations' => null,
    ]);
});

it('converts to array with partial usage information', function (): void {
    $usage = new Usage(
        promptTokens: 100,
        completionTokens: 50
    );

    $event = new StreamEndEvent(
        id: 'event-123',
        timestamp: 1640995200,
        finishReason: FinishReason::Stop,
        usage: $usage
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'finish_reason' => 'Stop',
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'cache_write_input_tokens' => null,
            'cache_read_input_tokens' => null,
            'thought_tokens' => null,
        ],
        'citations' => null,
    ]);
});

it('handles all finish reason types', function (FinishReason $reason): void {
    $event = new StreamEndEvent(
        id: 'event-123',
        timestamp: 1640995200,
        finishReason: $reason
    );

    expect($event->finishReason)->toBe($reason)
        ->and($event->toArray()['finish_reason'])->toBe($reason->name);
})->with([
    FinishReason::Stop,
    FinishReason::Length,
    FinishReason::ContentFilter,
    FinishReason::ToolCalls,
    FinishReason::Error,
    FinishReason::Other,
    FinishReason::Unknown,
]);
