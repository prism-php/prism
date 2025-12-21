<?php

declare(strict_types=1);

namespace Tests\Providers\Z;

use Prism\Prism\Providers\Z\Maps\MessageMap;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
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
