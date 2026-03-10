<?php

declare(strict_types=1);

namespace Tests\Providers\ModelsLab;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

beforeEach(function (): void {
    config()->set('prism.providers.modelslab.api_key', 'test-api-key');
});

it('can generate text', function (): void {
    Http::fake([
        'modelslab.com/api/v7/llm/chat/completions' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'llama-3.3-70b',
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
                'prompt_tokens' => 10,
                'completion_tokens' => 15,
                'total_tokens' => 25,
            ],
        ], 200),
    ]);

    $response = Prism::text()
        ->using(Provider::ModelsLab, 'llama-3.3-70b')
        ->withPrompt('Hello!')
        ->generate();

    expect($response->text)->toBe('Hello! How can I help you today?');
    expect($response->finishReason)->toBe(FinishReason::Stop);
    expect($response->usage->promptTokens)->toBe(10);
    expect($response->usage->completionTokens)->toBe(15);
    expect($response->meta->id)->toBe('chatcmpl-123');
    expect($response->meta->model)->toBe('llama-3.3-70b');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://modelslab.com/api/v7/llm/chat/completions' &&
               $data['model'] === 'llama-3.3-70b' &&
               $data['messages'][0]['role'] === 'user' &&
               $data['messages'][0]['content'] === 'Hello!' &&
               $request->hasHeader('Authorization', 'Bearer test-api-key');
    });
});

it('can generate text with system prompt', function (): void {
    Http::fake([
        'modelslab.com/api/v7/llm/chat/completions' => Http::response([
            'id' => 'chatcmpl-124',
            'model' => 'llama-3.3-70b',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I am a helpful assistant.',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 20,
                'completion_tokens' => 10,
            ],
        ], 200),
    ]);

    $response = Prism::text()
        ->using(Provider::ModelsLab, 'llama-3.3-70b')
        ->withSystemPrompt('You are a helpful assistant.')
        ->withPrompt('Who are you?')
        ->generate();

    expect($response->text)->toBe('I am a helpful assistant.');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['messages'][0]['role'] === 'system' &&
               $data['messages'][0]['content'] === 'You are a helpful assistant.' &&
               $data['messages'][1]['role'] === 'user' &&
               $data['messages'][1]['content'] === 'Who are you?';
    });
});

it('can generate text with temperature and top_p', function (): void {
    Http::fake([
        'modelslab.com/api/v7/llm/chat/completions' => Http::response([
            'id' => 'chatcmpl-125',
            'model' => 'llama-3.3-70b',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Creative response here.',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ], 200),
    ]);

    $response = Prism::text()
        ->using(Provider::ModelsLab, 'llama-3.3-70b')
        ->withPrompt('Be creative')
        ->usingTemperature(0.9)
        ->usingTopP(0.95)
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['temperature'] === 0.9 &&
               $data['top_p'] === 0.95;
    });
});

it('can generate text with max tokens', function (): void {
    Http::fake([
        'modelslab.com/api/v7/llm/chat/completions' => Http::response([
            'id' => 'chatcmpl-126',
            'model' => 'llama-3.3-70b',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Short response.',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 3,
            ],
        ], 200),
    ]);

    $response = Prism::text()
        ->using(Provider::ModelsLab, 'llama-3.3-70b')
        ->withPrompt('Test')
        ->withMaxTokens(100)
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['max_tokens'] === 100;
    });
});

it('can generate text with provider options', function (): void {
    Http::fake([
        'modelslab.com/api/v7/llm/chat/completions' => Http::response([
            'id' => 'chatcmpl-127',
            'model' => 'llama-3.3-70b',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Response with penalties.',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ], 200),
    ]);

    $response = Prism::text()
        ->using(Provider::ModelsLab, 'llama-3.3-70b')
        ->withPrompt('Test')
        ->withProviderOptions([
            'presence_penalty' => 0.5,
            'frequency_penalty' => 0.3,
        ])
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['presence_penalty'] === 0.5 &&
               $data['frequency_penalty'] === 0.3;
    });
});

it('includes raw response data', function (): void {
    Http::fake([
        'modelslab.com/api/v7/llm/chat/completions' => Http::response([
            'id' => 'chatcmpl-130',
            'model' => 'llama-3.3-70b',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Test response',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 5,
                'completion_tokens' => 3,
            ],
        ], 200),
    ]);

    $response = Prism::text()
        ->using(Provider::ModelsLab, 'llama-3.3-70b')
        ->withPrompt('Test')
        ->generate();

    expect($response->raw['id'])->toBe('chatcmpl-130');
    expect($response->raw['model'])->toBe('llama-3.3-70b');
});
