<?php

declare(strict_types=1);

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\TextStartEvent;

it('constructs with required parameters', function (): void {
    $event = new TextStartEvent(
        id: 'event-123',
        timestamp: 1640995200,
        messageId: 'msg-456'
    );

    expect($event->id)->toBe('event-123')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->messageId)->toBe('msg-456');
});

it('returns correct stream event type', function (): void {
    $event = new TextStartEvent(
        id: 'event-123',
        timestamp: 1640995200,
        messageId: 'msg-456'
    );

    expect($event->type())->toBe(StreamEventType::TextStart);
});

it('converts to array with all properties', function (): void {
    $event = new TextStartEvent(
        id: 'event-123',
        timestamp: 1640995200,
        messageId: 'msg-456'
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'message_id' => 'msg-456',
    ]);
});

it('handles empty string properties', function (): void {
    $event = new TextStartEvent(
        id: '',
        timestamp: 0,
        messageId: ''
    );

    expect($event->id)->toBe('')
        ->and($event->messageId)->toBe('');
});
