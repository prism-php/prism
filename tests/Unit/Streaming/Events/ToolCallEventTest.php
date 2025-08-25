<?php

declare(strict_types=1);

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\ToolCallEvent;

it('constructs with required parameters', function (): void {
    $arguments = ['query' => 'hello world', 'max_results' => 10];

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        toolName: 'search',
        arguments: $arguments,
        messageId: 'msg-789'
    );

    expect($event->id)->toBe('event-123')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->toolId)->toBe('tool-456')
        ->and($event->toolName)->toBe('search')
        ->and($event->arguments)->toBe($arguments)
        ->and($event->messageId)->toBe('msg-789')
        ->and($event->reasoningId)->toBeNull();
});

it('constructs with reasoning id', function (): void {
    $arguments = ['file' => 'data.txt'];

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        toolName: 'read_file',
        arguments: $arguments,
        messageId: 'msg-789',
        reasoningId: 'reasoning-101'
    );

    expect($event->reasoningId)->toBe('reasoning-101');
});

it('returns correct stream event type', function (): void {
    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        toolName: 'calculator',
        arguments: [],
        messageId: 'msg-789'
    );

    expect($event->type())->toBe(StreamEventType::ToolCall);
});

it('converts to array with all properties', function (): void {
    $arguments = [
        'expression' => '2 + 2',
        'precision' => 2,
        'format' => 'decimal',
    ];

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        toolName: 'calculator',
        arguments: $arguments,
        messageId: 'msg-789',
        reasoningId: 'reasoning-101'
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'tool_id' => 'tool-456',
        'tool_name' => 'calculator',
        'arguments' => $arguments,
        'message_id' => 'msg-789',
        'reasoning_id' => 'reasoning-101',
    ]);
});

it('converts to array with null reasoning id', function (): void {
    $arguments = ['prompt' => 'Generate a story'];

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        toolName: 'text_generator',
        arguments: $arguments,
        messageId: 'msg-789'
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'tool_id' => 'tool-456',
        'tool_name' => 'text_generator',
        'arguments' => $arguments,
        'message_id' => 'msg-789',
        'reasoning_id' => null,
    ]);
});

it('handles empty arguments array', function (): void {
    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        toolName: 'ping',
        arguments: [],
        messageId: 'msg-789'
    );

    expect($event->arguments)->toBe([])
        ->and($event->toArray()['arguments'])->toBe([]);
});

it('handles complex nested arguments', function (): void {
    $arguments = [
        'config' => [
            'timeout' => 30,
            'retries' => 3,
            'options' => ['verbose', 'cache'],
        ],
        'data' => [
            'users' => [
                ['name' => 'John', 'age' => 30],
                ['name' => 'Jane', 'age' => 25],
            ],
        ],
        'metadata' => [
            'version' => '1.0.0',
            'timestamp' => 1640995200,
        ],
    ];

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        toolName: 'complex_processor',
        arguments: $arguments,
        messageId: 'msg-789'
    );

    expect($event->arguments)->toBe($arguments)
        ->and($event->toArray()['arguments'])->toBe($arguments);
});

it('handles string and numeric arguments', function (): void {
    $arguments = [
        'string_param' => 'hello',
        'int_param' => 42,
        'float_param' => 3.14,
        'bool_param' => true,
        'null_param' => null,
    ];

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolId: 'tool-456',
        toolName: 'multi_type_tool',
        arguments: $arguments,
        messageId: 'msg-789'
    );

    expect($event->arguments)->toBe($arguments);
});

it('handles empty string properties', function (): void {
    $event = new ToolCallEvent(
        id: '',
        timestamp: 0,
        toolId: '',
        toolName: '',
        arguments: [],
        messageId: '',
        reasoningId: ''
    );

    expect($event->id)->toBe('')
        ->and($event->toolId)->toBe('')
        ->and($event->toolName)->toBe('')
        ->and($event->messageId)->toBe('')
        ->and($event->reasoningId)->toBe('');
});
