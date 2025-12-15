<?php

declare(strict_types=1);

namespace Tests\Providers\Z;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Z\Z;
use Tests\TestRequest;

test('Z provider handles text request', function (): void {
    Http::fake([
        '*/chat/completions' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'created' => 1677652288,
            'model' => 'z-model',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello! How can I help you today?',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 9,
                'completion_tokens' => 12,
                'total_tokens' => 21,
            ],
        ]),
    ]);

    $provider = new Z('test-api-key', 'https://api.z.ai/v1');
    $request = new TestRequest;

    $response = $provider->text($request);

    expect($response->text)->toBe('Hello! How can I help you today?');
    expect($response->finishReason)->toBe(FinishReason::Stop);
    expect($response->usage->promptTokens)->toBe(9);
    expect($response->usage->completionTokens)->toBe(12);
    expect($response->meta->id)->toBe('chatcmpl-123');
    expect($response->meta->model)->toBe('z-model');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'test-model' &&
               $data['max_tokens'] === 2048 &&
               $data['thinking']['type'] === 'disabled';
    });
});

test('Z provider handles tool calls', function (): void {
    Http::fake([
        '*/chat/completions' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'created' => 1677652288,
            'model' => 'z-model',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_123',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'test_function',
                                    'arguments' => '{"param": "value"}',
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 9,
                'completion_tokens' => 12,
                'total_tokens' => 21,
            ],
        ]),
    ]);

    $provider = new Z('test-api-key', 'https://api.z.ai/v1');
    $request = new TestRequest(
        tools: [
            [
                'name' => 'test_function',
                'description' => 'A test function',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'param' => ['type' => 'string'],
                    ],
                ],
            ],
        ]
    );

    $response = $provider->text($request);

    expect($response->steps)->toHaveCount(1);
    expect($response->steps[0]->toolCalls)->toHaveCount(1);
    expect($response->steps[0]->toolCalls[0]->name)->toBe('test_function');
    expect($response->steps[0]->finishReason)->toBe(FinishReason::ToolCalls);
});
