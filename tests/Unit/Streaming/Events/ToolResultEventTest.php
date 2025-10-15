<?php

declare(strict_types=1);

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\ToolResult;

it('constructs with required parameters', function (): void {
    $result = ['output' => 'Hello World', 'status' => 'completed'];
    $toolResult = new ToolResult(
        toolCallId: 'tool-456',
        toolName: 'greeting',
        args: ['name' => 'World'],
        result: $result
    );

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolResult: $toolResult,
        messageId: 'msg-789'
    );

    expect($event->id)->toBe('event-123')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->toolResult)->toBe($toolResult)
        ->and($event->toolResult->toolCallId)->toBe('tool-456')
        ->and($event->toolResult->result)->toBe($result)
        ->and($event->messageId)->toBe('msg-789')
        ->and($event->success)->toBeTrue()
        ->and($event->error)->toBeNull();
});

it('constructs with custom success and error', function (): void {
    $result = ['partial_data' => 'some data'];
    $toolResult = new ToolResult(
        toolCallId: 'tool-456',
        toolName: 'data_fetcher',
        args: ['source' => 'api'],
        result: $result
    );

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolResult: $toolResult,
        messageId: 'msg-789',
        success: false,
        error: 'Connection timeout'
    );

    expect($event->success)->toBeFalse()
        ->and($event->error)->toBe('Connection timeout');
});

it('returns correct stream event type', function (): void {
    $toolResult = new ToolResult(
        toolCallId: 'tool-456',
        toolName: 'test_tool',
        args: [],
        result: []
    );

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolResult: $toolResult,
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

    $toolResult = new ToolResult(
        toolCallId: 'tool-456',
        toolName: 'list_processor',
        args: ['items' => ['item1', 'item2', 'item3']],
        result: $result
    );

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolResult: $toolResult,
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
    $toolResult = new ToolResult(
        toolCallId: 'tool-456',
        toolName: 'data_fetcher',
        args: ['url' => 'https://example.com'],
        result: $result
    );

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolResult: $toolResult,
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
    $toolResult = new ToolResult(
        toolCallId: 'tool-456',
        toolName: 'empty_tool',
        args: [],
        result: []
    );

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolResult: $toolResult,
        messageId: 'msg-789'
    );

    expect($event->toolResult->result)->toBe([])
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

    $toolResult = new ToolResult(
        toolCallId: 'tool-456',
        toolName: 'user_fetcher',
        args: ['page' => 1, 'per_page' => 50],
        result: $result
    );

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolResult: $toolResult,
        messageId: 'msg-789'
    );

    expect($event->toolResult->result)->toBe($result)
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

    $toolResult = new ToolResult(
        toolCallId: 'tool-456',
        toolName: 'mixed_type_tool',
        args: ['type' => 'mixed'],
        result: $result
    );

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolResult: $toolResult,
        messageId: 'msg-789'
    );

    expect($event->toolResult->result)->toBe($result);
});

it('handles success false with null error', function (): void {
    $toolResult = new ToolResult(
        toolCallId: 'tool-456',
        toolName: 'failing_tool',
        args: [],
        result: ['status' => 'failed']
    );

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolResult: $toolResult,
        messageId: 'msg-789',
        success: false
    );

    expect($event->success)->toBeFalse()
        ->and($event->error)->toBeNull();
});

it('handles empty string error message', function (): void {
    $toolResult = new ToolResult(
        toolCallId: 'tool-456',
        toolName: 'empty_error_tool',
        args: [],
        result: []
    );

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolResult: $toolResult,
        messageId: 'msg-789',
        success: false,
        error: ''
    );

    expect($event->error)->toBe('');
});

it('handles multiline error message', function (): void {
    $error = "Error occurred:\nLine 1: Connection failed\nLine 2: Retry limit exceeded\nLine 3: Operation aborted";
    $toolResult = new ToolResult(
        toolCallId: 'tool-456',
        toolName: 'multiline_error_tool',
        args: ['operation' => 'complex'],
        result: []
    );

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolResult: $toolResult,
        messageId: 'msg-789',
        success: false,
        error: $error
    );

    expect($event->error)->toBe($error);
});

it('handles string result', function (): void {
    $stringResult = 'This is a simple string result';
    $toolResult = new ToolResult(
        toolCallId: 'tool-456',
        toolName: 'string_tool',
        args: ['input' => 'test'],
        result: $stringResult
    );

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolResult: $toolResult,
        messageId: 'msg-789'
    );

    expect($event->toolResult->result)->toBe($stringResult);
});

it('handles numeric result', function (): void {
    $numericResult = 42;
    $toolResult = new ToolResult(
        toolCallId: 'tool-456',
        toolName: 'calculation_tool',
        args: ['expression' => '6 * 7'],
        result: $numericResult
    );

    $event = new ToolResultEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolResult: $toolResult,
        messageId: 'msg-789'
    );

    expect($event->toolResult->result)->toBe($numericResult);
});
