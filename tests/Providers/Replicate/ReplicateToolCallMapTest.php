<?php

declare(strict_types=1);

use Prism\Prism\Providers\Replicate\Maps\ToolCallMap;
use Prism\Prism\ValueObjects\ToolCall;

it('parses valid JSON with single tool call', function (): void {
    $output = json_encode([
        'tool_calls' => [
            [
                'id' => 'call_abc123',
                'name' => 'get_weather',
                'arguments' => ['location' => 'Paris'],
            ],
        ],
    ]);

    $toolCalls = ToolCallMap::map($output);

    expect($toolCalls)->toHaveCount(1)
        ->and($toolCalls[0])->toBeInstanceOf(ToolCall::class)
        ->and($toolCalls[0]->id)->toBe('call_abc123')
        ->and($toolCalls[0]->name)->toBe('get_weather')
        ->and($toolCalls[0]->arguments())->toBe(['location' => 'Paris']);
});

it('parses multiple tool calls', function (): void {
    $output = json_encode([
        'tool_calls' => [
            [
                'id' => 'call_1',
                'name' => 'weather',
                'arguments' => ['city' => 'Paris'],
            ],
            [
                'id' => 'call_2',
                'name' => 'search',
                'arguments' => ['query' => 'events'],
            ],
        ],
    ]);

    $toolCalls = ToolCallMap::map($output);

    expect($toolCalls)->toHaveCount(2)
        ->and($toolCalls[0]->name)->toBe('weather')
        ->and($toolCalls[1]->name)->toBe('search');
});

it('extracts JSON from markdown code blocks', function (): void {
    $output = "Here's the tool call:\n```json\n".json_encode([
        'tool_calls' => [
            ['id' => 'call_1', 'name' => 'test', 'arguments' => []],
        ],
    ])."\n```\nEnd.";

    $toolCalls = ToolCallMap::map($output);

    expect($toolCalls)->toHaveCount(1)
        ->and($toolCalls[0]->name)->toBe('test');
});

it('extracts JSON with surrounding text', function (): void {
    $output = "I'll call the tool now: ".json_encode([
        'tool_calls' => [
            ['id' => 'call_1', 'name' => 'test', 'arguments' => []],
        ],
    ])." And that's it!";

    $toolCalls = ToolCallMap::map($output);

    expect($toolCalls)->toHaveCount(1);
});

it('generates ID when not provided', function (): void {
    $output = json_encode([
        'tool_calls' => [
            ['name' => 'test', 'arguments' => []],
        ],
    ]);

    $toolCalls = ToolCallMap::map($output);

    expect($toolCalls[0]->id)->toStartWith('call_')
        ->and(strlen($toolCalls[0]->id))->toBeGreaterThan(5);
});

it('returns empty array for non-tool response', function (): void {
    $output = 'This is just a regular text response without any tool calls.';

    $toolCalls = ToolCallMap::map($output);

    expect($toolCalls)->toBeEmpty();
});

it('handles array output from Replicate', function (): void {
    $output = [
        '{"tool_calls": [',
        '{"id": "call_1", "name": "test", "arguments": {}}',
        ']}',
    ];

    $toolCalls = ToolCallMap::map($output);

    expect($toolCalls)->toHaveCount(1);
});

it('correctly detects presence of tool calls', function (): void {
    $withTools = json_encode(['tool_calls' => [['name' => 'test']]]);
    $withoutTools = 'Just plain text';

    expect(ToolCallMap::hasToolCalls($withTools))->toBeTrue()
        ->and(ToolCallMap::hasToolCalls($withoutTools))->toBeFalse();
});

it('returns empty array for invalid JSON', function (): void {
    $output = '{"tool_calls": [invalid json here}';

    $toolCalls = ToolCallMap::map($output);

    expect($toolCalls)->toBeEmpty();
});
