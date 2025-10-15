<?php

declare(strict_types=1);

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\TextDeltaEvent;

it('constructs with required parameters', function (): void {
    $event = new TextDeltaEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: 'Hello',
        messageId: 'msg-456'
    );

    expect($event->id)->toBe('event-123')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->delta)->toBe('Hello')
        ->and($event->messageId)->toBe('msg-456');
});

it('returns correct stream event type', function (): void {
    $event = new TextDeltaEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: 'Hello',
        messageId: 'msg-456'
    );

    expect($event->type())->toBe(StreamEventType::TextDelta);
});

it('converts to array with all properties', function (): void {
    $event = new TextDeltaEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: 'Hello world!',
        messageId: 'msg-456'
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'delta' => 'Hello world!',
        'message_id' => 'msg-456',
    ]);
});

it('handles empty string delta', function (): void {
    $event = new TextDeltaEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: '',
        messageId: 'msg-456'
    );

    expect($event->delta)->toBe('');
});

it('handles unicode text delta', function (): void {
    $event = new TextDeltaEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: '🚀 Hello 世界!',
        messageId: 'msg-456'
    );

    expect($event->delta)->toBe('🚀 Hello 世界!')
        ->and($event->toArray()['delta'])->toBe('🚀 Hello 世界!');
});

it('handles multiline text delta', function (): void {
    $delta = "Line 1\nLine 2\nLine 3";

    $event = new TextDeltaEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: $delta,
        messageId: 'msg-456'
    );

    expect($event->delta)->toBe($delta);
});

it('handles special characters in delta', function (): void {
    $delta = "Special chars: \"'`!@#$%^&*(){}[]<>";

    $event = new TextDeltaEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: $delta,
        messageId: 'msg-456'
    );

    expect($event->delta)->toBe($delta);
});
