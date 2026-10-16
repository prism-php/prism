<?php

declare(strict_types=1);

namespace Tests\Providers\Z;

use Prism\Prism\Providers\Z\Maps\MessageMap;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

it('MessageMap maps messages correctly', function (): void {
    $messages = [
        new UserMessage('Hello, how are you?'),
        new AssistantMessage('I am doing well, thank you!'),
        new ToolResultMessage([
            new ToolResult(
                'tool_123',
                'tool_name',
                [],
                'result_data'
            ),
        ]),
    ];
    $systemPrompts = [
        new SystemMessage('You are a helpful assistant.'),
    ];

    $messageMap = new MessageMap($messages, $systemPrompts);
    $mapped = $messageMap();

    expect($mapped)->toHaveCount(4)
        ->and($mapped[0])->toBe([
            'role' => 'system',
            'content' => 'You are a helpful assistant.',
        ])
        ->and($mapped[1])->toBe([
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Hello, how are you?',
                ],
            ],
        ])
        ->and($mapped[2])->toBe([
            'role' => 'assistant',
            'content' => 'I am doing well, thank you!',
        ])
        ->and($mapped[3])->toBe([
            'role' => 'tool',
            'tool_call_id' => 'tool_123',
            'content' => 'result_data',
        ]);
});

it('maps assistant tool calls so replayed tool results have a matching call', function (): void {
    $messages = [
        new UserMessage('What is the weather in Detroit?'),
        new AssistantMessage('Let me check.', [
            new ToolCall('call_123', 'get_weather', ['city' => 'Detroit']),
            new ToolCall('call_456', 'get_time', []),
        ]),
        new ToolResultMessage([
            new ToolResult('call_123', 'get_weather', ['city' => 'Detroit'], 'Sunny'),
            new ToolResult('call_456', 'get_time', [], '3pm'),
        ]),
    ];

    $mapped = (new MessageMap($messages, []))();

    expect($mapped[1])->toBe([
        'role' => 'assistant',
        'content' => 'Let me check.',
        'tool_calls' => [
            [
                'id' => 'call_123',
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'arguments' => '{"city":"Detroit"}',
                ],
            ],
            [
                'id' => 'call_456',
                'type' => 'function',
                'function' => [
                    'name' => 'get_time',
                    'arguments' => '{}',
                ],
            ],
        ],
    ])
        ->and($mapped[2]['tool_call_id'])->toBe('call_123')
        ->and($mapped[3]['tool_call_id'])->toBe('call_456');
});
