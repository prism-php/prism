<?php

declare(strict_types=1);

namespace Tests\Providers\Z;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Providers\Z\Z;
use Tests\TestDoubles\TestRequest;

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

    expect($response->text)->toBe('Hello! How can I help you today?')
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->usage->promptTokens)->toBe(9)
        ->and($response->usage->completionTokens)->toBe(12)
        ->and($response->meta->id)->toBe('chatcmpl-123')
        ->and($response->meta->model)->toBe('z-model');

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
            Tool::as('test_function')
                ->for('A test function')
                ->withStringParameter('param', 'A parameter')
                ->using(fn (string $param): string => "Result: {$param}"),
        ],
        maxSteps: 1
    );

    $response = $provider->text($request);

    expect($response->steps)->toHaveCount(1)
        ->and($response->steps[0]->toolCalls)->toHaveCount(1)
        ->and($response->steps[0]->toolCalls[0]->name)->toBe('test_function')
        ->and($response->steps[0]->finishReason)->toBe(FinishReason::ToolCalls);
});
