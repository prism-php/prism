<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\ValueObjects\Usage;

it('returns correct stream event type', function (): void {
    $event = new StepFinishEvent(id: 'event-123', timestamp: 1640995200);

    expect($event->type())->toBe(StreamEventType::StepFinish);
});

it('converts to array with the finish reason name and step usage', function (): void {
    $event = new StepFinishEvent(
        id: 'event-123',
        timestamp: 1640995200,
        usage: new Usage(
            promptTokens: 100,
            completionTokens: 50,
            cacheWriteInputTokens: 25,
            cacheReadInputTokens: 10,
            thoughtTokens: 5
        ),
        finishReason: FinishReason::ToolCalls,
    );

    expect($event->toArray())->toBe([
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
    ]);
});

it('converts to array with a null finish reason when none was set', function (): void {
    $event = new StepFinishEvent(id: 'event-123', timestamp: 1640995200);

    expect($event->toArray())->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'finish_reason' => null,
        'usage' => null,
    ]);
});

it('keeps additional content out of the array', function (): void {
    $event = new StepFinishEvent(
        id: 'event-123',
        timestamp: 1640995200,
        additionalContent: ['content_blocks' => [['type' => 'text', 'text' => 'Hi']]],
        finishReason: FinishReason::Stop,
    );

    expect($event->toArray())->not->toHaveKey('content_blocks')
        ->and($event->toArray()['finish_reason'])->toBe('Stop');
});
