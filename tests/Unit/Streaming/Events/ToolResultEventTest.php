<?php

declare(strict_types=1);

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\ToolResultEvent;

it('constructs with required parameters', function (): void {
    $result = ['output' => 'Hello World', 'status' => 'completed'];

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        result: $result,
        messageId: 'msg-789'
    );

    expect($event->id)->toBe('event-123')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->toolId)->toBe('tool-456')
        ->and($event->result)->toBe($result)
        ->and($event->messageId)->toBe('msg-789')
        ->and($event->success)->toBeTrue()
        ->and($event->error)->toBeNull();
});

it('constructs with custom success and error', function (): void {
    $result = ['partial_data' => 'some data'];

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        result: $result,
        messageId: 'msg-789',
        success: false,
        error: 'Connection timeout'
    );

    expect($event->success)->toBeFalse()
        ->and($event->error)->toBe('Connection timeout');
});

it('returns correct stream event type', function (): void {
    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        result: [],
        messageId: 'msg-789'
    );

    expect($event->type())->toBe(StreamEventType::ToolResult);
});

it('converts to array with successful result', function (): void {
    $result = [
        'data' => ['item1', 'item2', 'item3'],
        'count' => 3,
        'processing_time' => 0.25,
    ];

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        result: $result,
        messageId: 'msg-789'
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'tool_id' => 'tool-456',
        'result' => $result,
        'message_id' => 'msg-789',
        'success' => true,
        'error' => null,
    ]);
});

it('converts to array with failed result', function (): void {
    $result = ['partial_output' => 'incomplete data'];

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        result: $result,
        messageId: 'msg-789',
        success: false,
        error: 'Network error: unable to fetch complete data'
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'tool_id' => 'tool-456',
        'result' => $result,
        'message_id' => 'msg-789',
        'success' => false,
        'error' => 'Network error: unable to fetch complete data',
    ]);
});

it('handles empty result array', function (): void {
    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        result: [],
        messageId: 'msg-789'
    );

    expect($event->result)->toBe([])
        ->and($event->toArray()['result'])->toBe([]);
});

it('handles complex nested result', function (): void {
    $result = [
        'status' => 'success',
        'data' => [
            'users' => [
                ['id' => 1, 'name' => 'John', 'active' => true],
                ['id' => 2, 'name' => 'Jane', 'active' => false],
            ],
            'metadata' => [
                'total_count' => 2,
                'query_time' => 0.15,
                'cache_hit' => true,
            ],
        ],
        'pagination' => [
            'current_page' => 1,
            'total_pages' => 1,
            'per_page' => 50,
        ],
    ];

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        result: $result,
        messageId: 'msg-789'
    );

    expect($event->result)->toBe($result)
        ->and($event->toArray()['result'])->toBe($result);
});

it('handles mixed data types in result', function (): void {
    $result = [
        'string_value' => 'text',
        'integer_value' => 42,
        'float_value' => 3.14159,
        'boolean_value' => true,
        'null_value' => null,
        'array_value' => [1, 2, 3],
    ];

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        result: $result,
        messageId: 'msg-789'
    );

    expect($event->result)->toBe($result);
});

it('handles success false with null error', function (): void {
    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        result: ['status' => 'failed'],
        messageId: 'msg-789',
        success: false
    );

    expect($event->success)->toBeFalse()
        ->and($event->error)->toBeNull();
});

it('handles empty string error message', function (): void {
    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        result: [],
        messageId: 'msg-789',
        success: false,
        error: ''
    );

    expect($event->error)->toBe('');
});

it('handles multiline error message', function (): void {
    $error = "Error occurred:\nLine 1: Connection failed\nLine 2: Retry limit exceeded\nLine 3: Operation aborted";

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        result: [],
        messageId: 'msg-789',
        success: false,
        error: $error
    );

    expect($event->error)->toBe($error);
});
