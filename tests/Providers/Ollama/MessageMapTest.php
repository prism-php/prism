<?php

declare(strict_types=1);

use Prism\Prism\Contracts\Message;
use Prism\Prism\Providers\Ollama\Maps\MessageMap;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

it('maps system messages correctly', function (): void {
    $systemMessage = new SystemMessage('System instruction');

    $messageMap = new MessageMap([$systemMessage]);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'system',
            'content' => 'System instruction',
        ],
    ]);
});

it('maps user messages correctly', function (): void {
    $userMessage = new UserMessage('User input');

    $messageMap = new MessageMap([$userMessage]);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'user',
            'content' => 'User input',
        ],
    ]);
});

it('maps user messages with images correctly', function (): void {
    $image = Image::fromLocalPath('tests/Fixtures/diamond.png');
    $userMessage = new UserMessage('User input with image', [$image]);

    $messageMap = new MessageMap([$userMessage]);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'user',
            'content' => 'User input with image',
            'images' => [base64_encode(file_get_contents('tests/Fixtures/diamond.png'))],
        ],
    ]);
});

it('maps assistant messages correctly', function (): void {
    $assistantMessage = new AssistantMessage('Assistant response');

    $messageMap = new MessageMap([$assistantMessage]);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'assistant',
            'content' => 'Assistant response',
        ],
    ]);
});

it('maps assistant messages with tool calls correctly', function (): void {
    $assistantMessage = new AssistantMessage('Assistant response', [
        new ToolCall(
            id: '',
            name: 'search',
            arguments: [
                'query' => 'What is Prism?',
            ]
        ),
    ]);

    $messageMap = new MessageMap([$assistantMessage]);
    $result = $messageMap->map();

    expect($result)->toEqual([
        [
            'role' => 'assistant',
            'content' => 'Assistant response',
            'tool_calls' => [[
                'function' => [
                    'name' => 'search',
                    'arguments' => (object) [
                        'query' => 'What is Prism?',
                    ],
                ],
            ]],
        ],
    ]);
});

it('maps tool result messages correctly', function (): void {
    $toolResult = new ToolResult(
        toolCallId: 'tool-1',
        toolName: 'test-tool',
        args: ['query' => 'test'],
        result: 'Tool execution result'
    );
    $toolResultMessage = new ToolResultMessage([$toolResult]);

    $messageMap = new MessageMap([$toolResultMessage]);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'tool',
            'tool_name' => 'test-tool',
            'content' => 'Tool execution result',
        ],
    ]);
});

it('maps tool result messages with non-string results correctly', function (): void {
    $toolResult = new ToolResult(
        toolCallId: 'tool-1',
        toolName: 'test-tool',
        args: ['query' => 'test'],
        result: ['key' => 'value']
    );
    $toolResultMessage = new ToolResultMessage([$toolResult]);

    $messageMap = new MessageMap([$toolResultMessage]);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'tool',
            'tool_name' => 'test-tool',
            'content' => '{"key":"value"}',
        ],
    ]);
});

it('maps multiple messages in sequence correctly', function (): void {
    $messages = [
        new SystemMessage('System instruction'),
        new UserMessage('User input'),
        new AssistantMessage('Assistant response'),
    ];

    $messageMap = new MessageMap($messages);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'system',
            'content' => 'System instruction',
        ],
        [
            'role' => 'user',
            'content' => 'User input',
        ],
        [
            'role' => 'assistant',
            'content' => 'Assistant response',
        ],
    ]);
});

it('throws exception for unknown message type', function (): void {
    $invalidMessage = new class implements Message {};
    $messageMap = new MessageMap([$invalidMessage]);

    expect($messageMap->map(...))
        ->toThrow(Exception::class, 'Could not map message type '.$invalidMessage::class);
});
