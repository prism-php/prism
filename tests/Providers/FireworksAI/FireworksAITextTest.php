<?php

declare(strict_types=1);

namespace Tests\Providers\FireworksAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

beforeEach(function (): void {
    config()->set('prism.providers.fireworksai.api_key', env('FIREWORKS_API_KEY', 'fw-1234'));
    config()->set('prism.providers.fireworksai.url', 'https://api.fireworks.ai/inference/v1');
});

describe('Text generation for FireworksAI', function (): void {
    it('can resolve the provider', function (): void {
        $manager = app(\Prism\Prism\PrismManager::class);
        $provider = $manager->resolve(\Prism\Prism\Enums\Provider::FireworksAI);

        expect($provider)->toBeInstanceOf(\Prism\Prism\Providers\FireworksAI\FireworksAI::class);
    });

    it('can generate text with a prompt', function (): void {
        Http::fake([
            'https://api.fireworks.ai/inference/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-fireworks-123',
                'object' => 'chat.completion',
                'created' => 1234567890,
                'model' => 'accounts/fireworks/models/llama-v3p3-70b-instruct',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I am an AI assistant created by Fireworks.ai.',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 15,
                    'total_tokens' => 25,
                ],
            ]),
        ]);

        $response = Prism::text()
            ->using(Provider::FireworksAI, 'accounts/fireworks/models/llama-v3p3-70b-instruct')
            ->withPrompt('Who are you?')
            ->asText();

        expect($response->usage->promptTokens)->toBe(10)
            ->and($response->usage->completionTokens)->toBe(15)
            ->and($response->text)->toBe('I am an AI assistant created by Fireworks.ai.')
            ->and($response->meta->id)->toBe('chatcmpl-fireworks-123')
            ->and($response->meta->model)->toBe('accounts/fireworks/models/llama-v3p3-70b-instruct');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.fireworks.ai/inference/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer fw-1234')
            && $request['model'] === 'accounts/fireworks/models/llama-v3p3-70b-instruct'
            && $request['messages'][0]['role'] === 'user'
            && $request['messages'][0]['content'][0]['text'] === 'Who are you?');
    });

    it('can use FireworksAI-specific parameters', function (): void {
        Http::fake([
            'https://api.fireworks.ai/inference/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-fireworks-456',
                'object' => 'chat.completion',
                'created' => 1234567890,
                'model' => 'accounts/fireworks/models/llama-v3p3-70b-instruct',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Response with custom parameters.',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                    'total_tokens' => 15,
                ],
            ]),
        ]);

        $response = Prism::text()
            ->using(Provider::FireworksAI, 'accounts/fireworks/models/llama-v3p3-70b-instruct')
            ->withPrompt('Test prompt')
            ->withProviderOptions([
                'context_length_exceeded_behavior' => 'truncate',
                'repetition_penalty' => 1.1,
                'mirostat_lr' => 0.1,
                'mirostat_target' => 5.0,
                'raw_output' => false,
                'echo' => false,
            ])
            ->asText();

        expect($response->text)->toBe('Response with custom parameters.');

        Http::assertSent(fn (Request $request): bool => $request['context_length_exceeded_behavior'] === 'truncate'
            && $request['repetition_penalty'] === 1.1
            && $request['mirostat_lr'] === 0.1
            && $request['mirostat_target'] === 5.0
            && $request['raw_output'] === false
            && $request['echo'] === false);
    });

    it('can use grammar mode', function (): void {
        Http::fake([
            'https://api.fireworks.ai/inference/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-fireworks-789',
                'object' => 'chat.completion',
                'created' => 1234567890,
                'model' => 'accounts/fireworks/models/llama-v3p3-70b-instruct',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"name": "John", "age": 30}',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 10,
                    'total_tokens' => 20,
                ],
            ]),
        ]);

        $gbnfGrammar = 'root ::= "{" ws "\"name\":" ws string ws "," ws "\"age\":" ws number ws "}"';

        $response = Prism::text()
            ->using(Provider::FireworksAI, 'accounts/fireworks/models/llama-v3p3-70b-instruct')
            ->withPrompt('Generate a person object')
            ->withProviderOptions(['grammar' => $gbnfGrammar])
            ->asText();

        expect($response->text)->toBe('{"name": "John", "age": 30}');

        Http::assertSent(fn (Request $request): bool => $request['response_format']['type'] === 'grammar'
            && $request['response_format']['grammar'] === $gbnfGrammar);
    });

    it('can use tool_choice any', function (): void {
        Http::fake([
            'https://api.fireworks.ai/inference/v1/chat/completions' => Http::sequence()
                ->push([
                    'id' => 'chatcmpl-fireworks-tool-any',
                    'object' => 'chat.completion',
                    'created' => 1234567890,
                    'model' => 'accounts/fireworks/models/llama-v3p3-70b-instruct',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => '{"location":"San Francisco"}',
                                ],
                            ]],
                        ],
                        'finish_reason' => 'tool_calls',
                    ]],
                    'usage' => [
                        'prompt_tokens' => 20,
                        'completion_tokens' => 10,
                        'total_tokens' => 30,
                    ],
                ])
                ->push([
                    'id' => 'chatcmpl-fireworks-tool-any-2',
                    'object' => 'chat.completion',
                    'created' => 1234567891,
                    'model' => 'accounts/fireworks/models/llama-v3p3-70b-instruct',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'The weather in San Francisco is sunny.',
                        ],
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => [
                        'prompt_tokens' => 40,
                        'completion_tokens' => 10,
                        'total_tokens' => 50,
                    ],
                ]),
        ]);

        $response = Prism::text()
            ->using(Provider::FireworksAI, 'accounts/fireworks/models/llama-v3p3-70b-instruct')
            ->withPrompt('Get the weather')
            ->withProviderOptions(['tool_choice' => 'any'])
            ->withTools([
                Tool::as('get_weather')
                    ->for('Get weather information')
                    ->withStringParameter('location', 'The location to get weather for')
                    ->using(fn (string $location): string => "Sunny in $location"),
            ])
            ->asText();

        expect($response->steps)->toHaveCount(1)
            ->and($response->steps[0]->toolCalls)->toHaveCount(1)
            ->and($response->steps[0]->toolCalls[0]->name)->toBe('get_weather')
            ->and($response->steps[0]->toolResults[0]->result)->toBe('Sunny in San Francisco')
            ->and($response->steps[0]->finishReason->name)->toBe('ToolCalls');
    });

    it('handles reasoning model output with think tags', function (): void {
        Http::fake([
            'https://api.fireworks.ai/inference/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-fireworks-reason',
                'object' => 'chat.completion',
                'created' => 1234567890,
                'model' => 'accounts/fireworks/models/deepthink-v1',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '<think>Let me think about this step by step...</think>The answer is 42.',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 20,
                    'total_tokens' => 30,
                ],
            ]),
        ]);

        $response = Prism::text()
            ->using(Provider::FireworksAI, 'accounts/fireworks/models/deepthink-v1')
            ->withPrompt('What is the meaning of life?')
            ->asText();

        expect($response->text)->toBe('The answer is 42.')
            ->and($response->steps->first()->additionalContent['reasoning'] ?? null)
            ->toBe('Let me think about this step by step...');
    });

    it('can use JSON mode with schema in structured output', function (): void {
        Http::fake([
            'https://api.fireworks.ai/inference/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-fireworks-json-schema',
                'object' => 'chat.completion',
                'created' => 1234567890,
                'model' => 'accounts/fireworks/models/llama-v3p3-70b-instruct',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"name": "John Doe", "age": 30}',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 20,
                    'completion_tokens' => 10,
                    'total_tokens' => 30,
                ],
            ]),
        ]);

        $schema = new ObjectSchema(
            'user',
            'User information',
            [
                new StringSchema('name', 'User name'),
                new NumberSchema('age', 'User age'),
            ],
            ['name', 'age']
        );

        $response = Prism::structured()
            ->using(Provider::FireworksAI, 'accounts/fireworks/models/llama-v3p3-70b-instruct')
            ->withPrompt('Extract user information')
            ->withSchema($schema)
            ->usingStructuredMode(StructuredMode::Json)
            ->asStructured();

        expect($response->structured)->toBeArray()
            ->and($response->structured['name'])->toBe('John Doe')
            ->and($response->structured['age'])->toBe(30);

        Http::assertSent(fn (Request $request): bool => isset($request['response_format'])
            && $request['response_format']['type'] === 'json_object'
            && isset($request['response_format']['schema']));
    });

    it('can use context_length_exceeded_behavior', function (): void {
        Http::fake([
            'https://api.fireworks.ai/inference/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-fireworks-truncate',
                'object' => 'chat.completion',
                'created' => 1234567890,
                'model' => 'accounts/fireworks/models/llama-v3p3-70b-instruct',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Response with truncation.',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 5,
                    'total_tokens' => 105,
                ],
            ]),
        ]);

        $response = Prism::text()
            ->using(Provider::FireworksAI, 'accounts/fireworks/models/llama-v3p3-70b-instruct')
            ->withPrompt('Very long prompt that might exceed context length...')
            ->withProviderOptions(['context_length_exceeded_behavior' => 'truncate'])
            ->asText();

        expect($response->text)->toBe('Response with truncation.');

        Http::assertSent(fn (Request $request): bool => $request['context_length_exceeded_behavior'] === 'truncate');
    });

    it('handles API errors correctly', function (): void {
        Http::fake([
            'https://api.fireworks.ai/inference/v1/chat/completions' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid model name',
                ],
            ], 400),
        ]);

        expect(fn (): \Prism\Prism\Text\Response => Prism::text()
            ->using(Provider::FireworksAI, 'invalid-model')
            ->withPrompt('Hello')
            ->asText()
        )->toThrow(\Prism\Prism\Exceptions\PrismException::class, 'FireworksAI Error: [invalid_request_error] Invalid model name');
    });

    it('handles rate limiting correctly', function (): void {
        Http::fake([
            'https://api.fireworks.ai/inference/v1/chat/completions' => Http::response(
                ['error' => ['type' => 'rate_limit_error', 'message' => 'Rate limit exceeded']],
                429,
                [
                    'retry-after' => '60',
                    'x-ratelimit-limit-requests' => '100',
                    'x-ratelimit-remaining-requests' => '0',
                    'x-ratelimit-reset-requests' => '60s',
                    'x-ratelimit-over-limit' => 'true',
                ]
            ),
        ]);

        expect(fn (): \Prism\Prism\Text\Response => Prism::text()
            ->using(Provider::FireworksAI, 'accounts/fireworks/models/llama-v3p3-70b-instruct')
            ->withPrompt('Hello')
            ->asText()
        )->toThrow(\Prism\Prism\Exceptions\PrismRateLimitedException::class);
    });
});
