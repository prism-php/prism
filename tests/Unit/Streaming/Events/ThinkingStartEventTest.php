<?php

declare(strict_types=1);

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;

it('constructs with required parameters', function (): void {
    $event = new ThinkingStartEvent(
        id: 'event-123',
        timestamp: 1640995200,
        reasoningId: 'reasoning-456'
    );

    expect($event->id)->toBe('event-123')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->reasoningId)->toBe('reasoning-456');
});

it('returns correct stream event type', function (): void {
    $event = new ThinkingStartEvent(
        id: 'event-123',
        timestamp: 1640995200,
        reasoningId: 'reasoning-456'
    );

    expect($event->type())->toBe(StreamEventType::ThinkingStart);
});

it('converts to array with all properties', function (): void {
    $event = new ThinkingStartEvent(
        id: 'event-123',
        timestamp: 1640995200,
        reasoningId: 'reasoning-456'
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'reasoning_id' => 'reasoning-456',
    ]);
});

it('handles empty string properties', function (): void {
    $event = new ThinkingStartEvent(
        id: '',
        timestamp: 0,
        reasoningId: ''
    );

    expect($event->id)->toBe('')
        ->and($event->reasoningId)->toBe('');
});

it('handles long reasoning id', function (): void {
    $reasoningId = str_repeat('a', 1000);

    $event = new ThinkingStartEvent(
        id: 'event-123',
        timestamp: 1640995200,
        reasoningId: $reasoningId
    );

    expect($event->reasoningId)->toBe($reasoningId);
});
