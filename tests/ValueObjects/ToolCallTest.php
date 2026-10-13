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

it('escapes raw control characters inside string values instead of dropping them', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: "{\"code\": \"line one\nline two\tindented\"}"
    );

    expect($toolCall->arguments())->toBe([
        'code' => "line one\nline two\tindented",
    ]);
});

it('drops invalid control characters outside string values', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: "{\"param\":\x01 \"value\"}"
    );

    expect($toolCall->arguments())->toBe(['param' => 'value']);
});

it('does not mangle escape sequences already present in valid JSON', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: '{"text": "a\\nb \\"quoted\\" c\\\\d"}'
    );

    expect($toolCall->arguments())->toBe([
        'text' => "a\nb \"quoted\" c\\d",
    ]);
});

it('handles JSON null string arguments correctly', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: 'null'
    );

    expect($toolCall->arguments())->toBe([]);
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
