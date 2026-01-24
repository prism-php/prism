<?php

declare(strict_types=1);

use Prism\Prism\ValueObjects\ToolCall;

it('handles empty string arguments correctly', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: ''
    );

    expect($toolCall->arguments)->toBe('');
    expect($toolCall->arguments())->toBe([]);
});

it('handles null arguments correctly', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: []
    );

    expect($toolCall->arguments)->toBe([]);
    expect($toolCall->arguments())->toBe([]);
});

it('handles empty object arguments correctly', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: '{}'
    );

    expect($toolCall->arguments)->toBe('{}');
    expect($toolCall->arguments())->toBe([]);
});

it('handles valid JSON string arguments correctly', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: '{"param1": "value1", "param2": 42}'
    );

    expect($toolCall->arguments)->toBe(
        '{"param1": "value1", "param2": 42}'
    );

    expect($toolCall->arguments())->toBe([
        'param1' => 'value1',
        'param2' => 42,
    ]);
});

it('handles array arguments correctly', function (): void {
    $arguments = ['param1' => 'value1', 'param2' => 42];

    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: $arguments
    );

    expect($toolCall->arguments)->toBe($arguments);
    expect($toolCall->arguments())->toBe($arguments);
});

it('throws exception for malformed JSON string arguments', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: '{"invalid json"'
    );

    expect($toolCall->arguments)->toBe('{"invalid json"');
    expect($toolCall->arguments(...))->toThrow(JsonException::class);
});
