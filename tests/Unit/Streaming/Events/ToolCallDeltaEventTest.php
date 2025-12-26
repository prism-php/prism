<?php

declare(strict_types=1);

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;

it('constructs with required parameters', function (): void {
    $event = new ToolCallDeltaEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'toolu_123456789X',
        toolName: 'weather_tool',
        delta: '{json: "part"',
        messageId: 'msg-456'
    );

    expect($event->id)->toBe('event-123')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->toolId)->toBe('toolu_123456789X')
        ->and($event->toolName)->toBe('weather_tool')
        ->and($event->delta)->toBe('{json: "part"')
        ->and($event->messageId)->toBe('msg-456');
});

it('returns correct stream event type', function (): void {
    $event = new ToolCallDeltaEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'toolu_123456789X',
        toolName: 'weather_tool',
        delta: '{json: "part"',
        messageId: 'msg-456'
    );

    expect($event->type())->toBe(StreamEventType::ToolCallDelta);
});

it('converts to array with all properties', function (): void {
    $event = new ToolCallDeltaEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'toolu_123456789X',
        toolName: 'weather_tool',
        delta: '{json: "part"',
        messageId: 'msg-456'
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'tool_id' => 'toolu_123456789X',
        'tool_name' => 'weather_tool',
        'delta' => '{json: "part"',
        'message_id' => 'msg-456',
    ]);
});
