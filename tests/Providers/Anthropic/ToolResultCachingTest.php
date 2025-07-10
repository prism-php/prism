<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Prism\Prism\Prism;
use Prism\Prism\Providers\Anthropic\Handlers\Text;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-1234'));
});

it('applies tool_result_cache_type only to the last tool result message across all messages', function (): void {
    // Create test messages simulating multiple tool call rounds
    $messages = [
        new UserMessage('What time is the tigers game today and should I wear a coat?'),
        new AssistantMessage('', toolCalls: [
            new ToolCall(
                id: 'call_1',
                name: 'search',
                arguments: ['query' => 'Detroit Tigers baseball game time today']
            ),
        ]),
        new ToolResultMessage([
            new ToolResult(
                toolCallId: 'call_1',
                toolName: 'search',
                args: ['query' => 'Detroit Tigers baseball game time today'],
                result: 'The tigers game is at 3pm in detroit'
            ),
        ]),
        new AssistantMessage('', toolCalls: [
            new ToolCall(
                id: 'call_2',
                name: 'weather',
                arguments: ['city' => 'Detroit']
            ),
        ]),
        new ToolResultMessage([
            new ToolResult(
                toolCallId: 'call_2',
                toolName: 'weather',
                args: ['city' => 'Detroit'],
                result: 'The weather will be 75° and sunny'
            ),
        ]),
        new AssistantMessage('The Tigers game is at 3pm today. The weather will be 75° and sunny, so you won\'t need a coat!'),
    ];

    // Map the messages with provider options
    $mappedMessages = MessageMap::map(
        $messages,
        ['tool_result_cache_type' => 'ephemeral']
    );

    // Verify that only the last tool result message has cache_control
    $toolResultMessages = array_filter($mappedMessages, fn ($message): bool => $message['role'] === 'user' &&
           isset($message['content'][0]['type']) &&
           $message['content'][0]['type'] === 'tool_result');

    expect(count($toolResultMessages))->toBe(2);

    // Get the tool result messages by their indices
    $toolResultIndices = array_keys($toolResultMessages);
    $firstToolResultIndex = $toolResultIndices[0];
    $lastToolResultIndex = $toolResultIndices[1];

    // First tool result should NOT have cache_control
    $firstToolResult = $mappedMessages[$firstToolResultIndex];
    expect($firstToolResult['content'][0])->not->toHaveKey('cache_control');

    // Last tool result SHOULD have cache_control
    $lastToolResult = $mappedMessages[$lastToolResultIndex];
    expect($lastToolResult['content'][0])->toHaveKey('cache_control');
    expect($lastToolResult['content'][0]['cache_control'])->toBe(['type' => 'ephemeral']);
});

it('handles single tool result message with cache_control', function (): void {
    $messages = [
        new UserMessage('What is the weather?'),
        new AssistantMessage('', toolCalls: [
            new ToolCall(
                id: 'call_1',
                name: 'weather',
                arguments: ['city' => 'Detroit']
            ),
        ]),
        new ToolResultMessage([
            new ToolResult(
                toolCallId: 'call_1',
                toolName: 'weather',
                args: ['city' => 'Detroit'],
                result: 'The weather will be 75° and sunny'
            ),
        ]),
    ];

    // Map the messages with provider options
    $mappedMessages = MessageMap::map(
        $messages,
        ['tool_result_cache_type' => 'ephemeral']
    );

    // Find the tool result message
    $toolResultMessage = null;
    foreach ($mappedMessages as $message) {
        if ($message['role'] === 'user' &&
            isset($message['content'][0]['type']) &&
            $message['content'][0]['type'] === 'tool_result') {
            $toolResultMessage = $message;
            break;
        }
    }

    // The single tool result should have cache_control
    expect($toolResultMessage)->not->toBeNull();
    expect($toolResultMessage['content'][0])->toHaveKey('cache_control');
    expect($toolResultMessage['content'][0]['cache_control'])->toBe(['type' => 'ephemeral']);
});

it('does not apply cache_control when tool_result_cache_type is not set', function (): void {
    $messages = [
        new UserMessage('What is the weather?'),
        new AssistantMessage('', toolCalls: [
            new ToolCall(
                id: 'call_1',
                name: 'weather',
                arguments: ['city' => 'Detroit']
            ),
        ]),
        new ToolResultMessage([
            new ToolResult(
                toolCallId: 'call_1',
                toolName: 'weather',
                args: ['city' => 'Detroit'],
                result: 'The weather will be 75° and sunny'
            ),
        ]),
    ];

    // Map the messages without provider options
    $mappedMessages = MessageMap::map($messages);

    // Find the tool result message
    $toolResultMessage = null;
    foreach ($mappedMessages as $message) {
        if ($message['role'] === 'user' &&
            isset($message['content'][0]['type']) &&
            $message['content'][0]['type'] === 'tool_result') {
            $toolResultMessage = $message;
            break;
        }
    }

    // Should not have cache_control
    expect($toolResultMessage)->not->toBeNull();
    expect($toolResultMessage['content'][0])->not->toHaveKey('cache_control');
});

it('sends only one cache block when request has multiple tool results in full lifecycle', function (): void {
    Prism::fake();

    // Simulate a request that already has multiple tool call rounds in history
    $request = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-latest')
        ->withMessages([
            new UserMessage('What time is the game and weather?'),
            new AssistantMessage('', toolCalls: [
                new ToolCall(
                    id: 'call_1',
                    name: 'search',
                    arguments: ['query' => 'game time']
                ),
            ]),
            new ToolResultMessage([
                new ToolResult(
                    toolCallId: 'call_1',
                    toolName: 'search',
                    args: ['query' => 'game time'],
                    result: '3pm'
                ),
            ]),
            new AssistantMessage('', toolCalls: [
                new ToolCall(
                    id: 'call_2',
                    name: 'weather',
                    arguments: ['city' => 'Detroit']
                ),
            ]),
            new ToolResultMessage([
                new ToolResult(
                    toolCallId: 'call_2',
                    toolName: 'weather',
                    args: ['city' => 'Detroit'],
                    result: 'sunny'
                ),
            ]),
        ])
        ->withProviderOptions(['tool_result_cache_type' => 'ephemeral']);

    // Get the actual payload that would be sent
    $payload = Text::buildHttpRequestPayload($request->toRequest());

    // Count cache blocks in the payload
    $cacheBlocks = 0;
    foreach ($payload['messages'] as $message) {
        foreach ($message['content'] as $content) {
            if (isset($content['cache_control'])) {
                $cacheBlocks++;
            }
        }
    }

    expect($cacheBlocks)->toBe(1);

    // Find the last tool result message
    $lastToolResultIndex = null;
    for ($i = count($payload['messages']) - 1; $i >= 0; $i--) {
        if ($payload['messages'][$i]['role'] === 'user' &&
            isset($payload['messages'][$i]['content'][0]['type']) &&
            $payload['messages'][$i]['content'][0]['type'] === 'tool_result') {
            $lastToolResultIndex = $i;
            break;
        }
    }

    // Verify the cache is on the last tool result
    expect($lastToolResultIndex)->not->toBeNull();
    expect($payload['messages'][$lastToolResultIndex]['content'][0])->toHaveKey('cache_control');
    expect($payload['messages'][$lastToolResultIndex]['content'][0]['cache_control'])->toBe(['type' => 'ephemeral']);

    // Verify earlier tool results don't have cache
    for ($i = 0; $i < $lastToolResultIndex; $i++) {
        if ($payload['messages'][$i]['role'] === 'user' &&
            isset($payload['messages'][$i]['content'][0]['type']) &&
            $payload['messages'][$i]['content'][0]['type'] === 'tool_result') {
            expect($payload['messages'][$i]['content'][0])->not->toHaveKey('cache_control');
        }
    }
});
