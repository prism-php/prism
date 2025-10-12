<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\Usage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'fake-key'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $chunks = [];

    $responseId = null;
    $model = null;

    foreach ($response as $chunk) {
        if ($chunk->meta) {
            $responseId = $chunk->meta?->id;
            $model = $chunk->meta?->model;
        }

        $chunks[] = $chunk;
        $text .= $chunk->text;
    }

    expect($chunks)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();
    expect($responseId)
        ->not->toBeNull()
        ->toStartWith('resp_');
    expect($model)->not->toBeNull();

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.openai.com/v1/responses'
            && $body['stream'] === true;
    });
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-with-tools-responses');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),

        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => "Search results for: {$query}"),
    ];

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStream();

    $text = '';
    $chunks = [];
    $toolCalls = [];
    $toolResults = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;

        if ($chunk->chunkType === ChunkType::ToolCall) {
            $toolCalls = array_merge($toolCalls, $chunk->toolCalls);
        }

        if ($chunk->chunkType === ChunkType::ToolResult) {
            $toolResults = array_merge($toolResults, $chunk->toolResults);
        }

        $text .= $chunk->text;
    }

    expect($chunks)->not->toBeEmpty();
    expect($toolCalls)->toHaveCount(2);
    expect($toolResults)->toHaveCount(2);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.openai.com/v1/responses'
            && isset($body['tools'])
            && $body['stream'] === true;
    });
});

it('can process a complete conversation with multiple tool calls', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-multi-tool-conversation-responses');

    $tools = [
        Tool::as('weather')
            ->for('Get weather information')
            ->withStringParameter('city', 'City name')
            ->using(fn (string $city): string => "The weather in {$city} is 75° and sunny."),

        Tool::as('search')
            ->for('Search for information')
            ->withStringParameter('query', 'The search query')
            ->using(fn (string $query): string => 'Tigers game is at 3pm in Detroit today.'),
    ];

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withTools($tools)
        ->withMaxSteps(5) // Allow multiple tool call rounds
        ->withPrompt('What time is the Tigers game today and should I wear a coat in Detroit?')
        ->asStream();

    $fullResponse = '';
    $toolCallCount = 0;

    foreach ($response as $chunk) {
        if ($chunk->toolCalls !== []) {
            $toolCallCount += count($chunk->toolCalls);
        }
        $fullResponse .= $chunk->text;
    }

    expect($toolCallCount)->toBe(2);
    expect($fullResponse)->not->toBeEmpty();

    // Verify we made multiple requests for a conversation with tool calls
    Http::assertSentCount(2);
});

it('can process a complete conversation with multiple tool calls for reasoning models', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-multi-tool-conversation-responses-reasoning');
    $tools = [
        Tool::as('weather')
            ->for('Get weather information')
            ->withStringParameter('city', 'City name')
            ->using(fn (string $city): string => "The weather in {$city} is 75° and sunny."),

        Tool::as('search')
            ->for('Search for information')
            ->withStringParameter('query', 'The search query')
            ->using(fn (string $query): string => 'Tigers game is at 3pm in Detroit today.'),
    ];

    $response = Prism::text()
        ->using('openai', 'o3-mini')
        ->withTools($tools)
        ->withMaxSteps(5) // Allow multiple tool call rounds
        ->withPrompt('What time is the Tigers game today and should I wear a coat in Detroit?')
        ->asStream();

    $fullResponse = '';
    $toolCallCount = 0;

    foreach ($response as $chunk) {
        if ($chunk->toolCalls !== []) {
            $toolCallCount += count($chunk->toolCalls);
        }
        $fullResponse .= $chunk->text;
    }

    expect($toolCallCount)->toBe(2);
    expect($fullResponse)->not->toBeEmpty();

    // Verify we made multiple requests for a conversation with tool calls
    Http::assertSentCount(3);
});

it('can process a complete conversation with multiple tool calls for reasoning models that require past reasoning', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-multi-tool-conversation-responses-reasoning-past-reasoning');
    $tools = [
        Tool::as('weather')
            ->for('Get weather information')
            ->withStringParameter('city', 'City name')
            ->using(fn (string $city): string => "The weather in {$city} is 75° and sunny."),

        Tool::as('search')
            ->for('Search for information')
            ->withStringParameter('query', 'The search query')
            ->using(fn (string $query): string => 'Tigers game is at 3pm in Detroit today.'),
    ];

    $response = Prism::text()
        ->using('openai', 'o4-mini')
        ->withProviderOptions([
            'reasoning' => [
                'effort' => 'low',
                'summary' => 'detailed',
            ],
        ])
        ->withTools($tools)
        ->withMaxSteps(5) // Allow multiple tool call rounds
        ->withPrompt('What time is the Tigers game today and should I wear a coat in Detroit?')
        ->asStream();

    $answerText = '';
    $toolCallCount = 0;
    $reasoningText = '';
    /** @var Usage[] $usage */
    $usage = [];

    foreach ($response as $chunk) {
        if ($chunk->toolCalls !== []) {
            $toolCallCount += count($chunk->toolCalls);
        }

        if ($chunk->chunkType === ChunkType::Thinking) {
            $reasoningText .= $chunk->text;
        } else {
            $answerText .= $chunk->text;
        }

        if ($chunk->usage) {
            $usage[] = $chunk->usage;
        }
    }

    expect($toolCallCount)->toBe(2);
    expect($answerText)->not->toBeEmpty();
    expect($reasoningText)->not->toBeEmpty();

    // Verify reasoning usage
    expect($usage[0]->thoughtTokens)->toBeGreaterThan(0);

    // Verify we made multiple requests for a conversation with tool calls
    Http::assertSentCount(3);
});

it('can process a complete conversation with provider tool', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-with-provider-tool');
    $tools = [
        new ProviderTool('web_search_preview'),
    ];

    $response = Prism::text()
        ->using('openai', 'o4-mini')
        ->withProviderTools($tools)
        ->withMaxSteps(5) // Allow multiple tool call rounds
        ->withPrompt('Search the web to retrieve the exact multiplicator to turn centimeters into inches.')
        ->asStream();

    $answerText = '';
    $toolCallCount = 0;
    /** @var Usage[] $usage */
    $usage = [];

    foreach ($response as $chunk) {
        if ($chunk->toolCalls !== []) {
            $toolCallCount += count($chunk->toolCalls);
        }

        if ($chunk->chunkType === ChunkType::Text) {
            $answerText .= $chunk->text;
        }

        if ($chunk->usage) {
            $usage[] = $chunk->usage;
        }
    }

    expect($toolCallCount)->toBe(0); // We currently don't count provider tools as tool calls.
    expect($answerText)->not->toBeEmpty();

    // Verify we made multiple requests for a conversation with tool calls
    Http::assertSentCount(1);
});

it('can pass parallel tool call setting', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-multi-tool-conversation-responses');

    $tools = [
        Tool::as('weather')
            ->for('Get weather information')
            ->withStringParameter('city', 'City name')
            ->using(fn (string $city): string => "The weather in {$city} is 75° and sunny."),

        Tool::as('search')
            ->for('Search for information')
            ->withStringParameter('query', 'The search query')
            ->using(fn (string $query): string => 'Tigers game is at 3pm in Detroit today.'),
    ];

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withTools($tools)
        ->withProviderOptions(['parallel_tool_calls' => false])
        ->withMaxSteps(5) // Allow multiple tool call rounds
        ->withPrompt('What time is the Tigers game today and should I wear a coat in Detroit?')
        ->asStream();

    $fullResponse = '';
    $toolCallCount = 0;

    foreach ($response as $chunk) {
        if ($chunk->toolCalls !== []) {
            $toolCallCount += count($chunk->toolCalls);
        }
        $fullResponse .= $chunk->text;
    }

    expect($toolCallCount)->toBe(2);
    expect($fullResponse)->not->toBeEmpty();

    Http::assertSent(fn (Request $request): bool => $request->data()['parallel_tool_calls'] === false);
});

it('emits usage information', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $chunk) {
        if ($chunk->usage) {
            expect($chunk->usage->promptTokens)->toBeGreaterThan(0);
            expect($chunk->usage->completionTokens)->toBeGreaterThan(0);
        }
    }
});

it('can accept falsy parameters', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-falsy-argument-conversation-responses');

    $modelTool = Tool::as('get_models')
        ->for('Returns info about of available models')
        ->withNumberParameter('modelId', 'Id of the model to load. Returns all models if null', false)
        ->using(fn (int $modelId): string => "The model {$modelId} is the funniest of all");

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Can you tell me more about the model with id 0 ?')
        ->withTools([$modelTool])
        ->withMaxSteps(2)
        ->asStream();

    foreach ($response as $chunk) {
        ob_flush();
        flush();
    }
})->throwsNoExceptions();

it('throws a PrismException on an unknown error', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-unknown-error-responses');

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $chunk) {
        // Read stream
    }
})->throws(PrismException::class, 'Sending to model gpt-4 failed. Code: unknown-error. Message: Foobar');

it('sends reasoning effort when defined', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-reasoning-effort');

    $response = Prism::text()
        ->using('openai', 'gpt-5')
        ->withPrompt('Who are you?')
        ->withProviderOptions([
            'reasoning' => [
                'effort' => 'low',
            ],
        ])
        ->asStream();

    // process stream
    collect($response);

    Http::assertSent(fn (Request $request): bool => $request->data()['reasoning']['effort'] === 'low');
});
