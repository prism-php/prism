<?php

declare(strict_types=1);

namespace Tests\Providers\Groq;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.groq.api_key', env('GROQ_API_KEY', 'test-key-12345'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('openai/v1/chat/completions', 'groq/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Groq, 'llama-3.1-70b-versatile')
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $chunks = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;
        $text .= $chunk->text;
    }

    expect($chunks)
        ->not->toBeEmpty()
        ->and($text)->not->toBeEmpty()
        ->and($text)->toContain(
            'Hello! I\'m Groq AI, your incredibly fast and efficient AI assistant. I\'m here to help you with any questions you might have. How can I assist you today?'
        );
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeStreamResponses('openai/v1/chat/completions', 'groq/stream-with-tools');

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
        ->using(Provider::Groq, 'llama-3.1-70b-versatile')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withMessages([
            new UserMessage('What time is the tigers game today and should I wear a coat?'),
        ])
        ->asStream();

    $text = '';
    $chunks = [];
    $toolResults = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;

        if ($chunk->toolCalls !== []) {
            expect($chunk->toolCalls[0]->name)
                ->toBeString()
                ->and($chunk->toolCalls[0]->name)->not
                ->toBeEmpty()
                ->and($chunk->toolCalls[0]->arguments())->toBeArray();
        }

        if ($chunk->toolResults !== []) {
            $toolResults = array_merge($toolResults, $chunk->toolResults);
        }

        if ($chunk->finishReason !== null) {
            expect($chunk->finishReason)->toBeInstanceOf(FinishReason::class);
        }

        $text .= $chunk->text;
    }

    expect($chunks)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();
});

it('handles maximum tool call depth exceeded', function (): void {
    FixtureResponse::fakeStreamResponses('openai/v1/chat/completions', 'groq/stream-with-tools');

    $tools = [
        Tool::as('weather')
            ->for('A tool that will be called recursively')
            ->withStringParameter('input', 'Any input')
            ->using(fn (string $input): string => 'This is a recursive response that will trigger another tool call.'),
    ];

    $response = Prism::text()
        ->using(Provider::Groq, 'llama-3.1-70b-versatile')
        ->withTools($tools)
        ->withMaxSteps(0) // Set very low to trigger the max depth exception
        ->withPrompt('Call the weather tool multiple times')
        ->asStream();

    $exception = null;

    try {
        // Consume the generator to trigger the exception
        foreach ($response as $chunk) {
            // The test should throw before completing
            // ...
        }
    } catch (PrismException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(PrismException::class);
    expect($exception->getMessage())->toContain('Maximum tool call chain depth exceeded');
});

it('handles invalid stream data correctly', function (): void {
    Http::fake([
        '*' => Http::response(
            "data: {invalid-json}\n\ndata: more invalid data\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        ),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using(Provider::Groq, 'llama-3.1-70b-versatile')
        ->withPrompt('This will trigger invalid JSON')
        ->asStream();

    $exception = null;

    try {
        // Consume the generator to trigger the exception
        foreach ($response as $chunk) {
            // The test should throw before completing
        }
    } catch (PrismChunkDecodeException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(PrismChunkDecodeException::class);
});

it('respects system prompts in the requests', function (): void {
    Http::fake([
        '*' => Http::response(
            "data: {\"choices\": [{\"delta\": {\"content\": \"Hello\"}}]}\n\ndata: {\"choices\": [{\"delta\": {\"content\": \" world\"}}, {\"done\": true}]}\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        ),
    ])->preventStrayRequests();

    $systemPrompt = 'You are a helpful assistant.';

    Prism::text()
        ->using(Provider::Groq, 'llama-3.1-70b-versatile')
        ->withSystemPrompt($systemPrompt)
        ->withPrompt('Say hello')
        ->asStream()
        ->current(); // Just trigger the first request

    Http::assertSent(function ($request) use ($systemPrompt): bool {
        $data = $request->data();

        // Check if a system prompt is included in the messages
        $hasSystemPrompt = false;
        foreach ($data['messages'] as $message) {
            if ($message['role'] === 'system' && $message['content'] === $systemPrompt) {
                $hasSystemPrompt = true;
                break;
            }
        }

        return $hasSystemPrompt;
    });
});

it('verifies correct request structure for streaming', function (): void {
    FixtureResponse::fakeStreamResponses('openai/v1/chat/completions', 'groq/stream-basic-text');
    Http::fake([
        '*' => Http::response(
            "data: {\"choices\": [{\"delta\": {\"content\": \"Hello\"}}]}\n\ndata: {\"choices\": [{\"delta\": {\"content\": \" world\"}}, {\"done\": true}]}\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        ),
    ])->preventStrayRequests();

    Prism::text()
        ->using(Provider::Groq, 'llama-3.1-70b-versatile')
        ->withPrompt('Test')
        ->asStream()
        ->current();

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        // Verify streaming parameters
        expect($data['stream'])->toBe(true);
        expect($data['model'])->toBe('llama-3.1-70b-versatile');
        expect($data['messages'])->toBeArray();
        expect($data['messages'][0]['role'])->toBe('user');
        expect($data['messages'][0]['content'])->toBe([['type' => 'text', 'text' => 'Test']]);

        return true;
    });
});

it('can handle chunk types correctly', function (): void {
    FixtureResponse::fakeStreamResponses('openai/v1/chat/completions', 'groq/stream-with-tools');

    $tools = [
        Tool::as('search')
            ->for('A search tool')
            ->withStringParameter('query', 'The search query')
            ->using(fn (string $query): string => "Search results for {$query}"),
        Tool::as('weather')
            ->for('A weather tool')
            ->withStringParameter('city', 'The city name')
            ->using(fn (string $city): string => "Weather in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Groq, 'llama-3.1-70b-versatile')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('Get weather for Detroit')
        ->asStream();

    $hasToolCallChunk = false;
    $hasToolResultChunk = false;
    $hasTextChunk = false;

    foreach ($response as $chunk) {
        if ($chunk->chunkType !== null) {
            switch ($chunk->chunkType) {
                case \Prism\Prism\Enums\ChunkType::ToolCall:
                    $hasToolCallChunk = true;
                    break;
                case \Prism\Prism\Enums\ChunkType::ToolResult:
                    $hasToolResultChunk = true;
                    break;
            }
        }

        if ($chunk->text !== '') {
            $hasTextChunk = true;
        }
    }

    expect($hasToolCallChunk)->toBe(true);
    expect($hasToolResultChunk)->toBe(true);
    expect($hasTextChunk)->toBe(true);
});

it('handles rate limiting correctly', function (): void {
    Http::fake([
        '*' => Http::response(
            'Too many requests',
            429,
            [
                'x-ratelimit-limit-requests' => '500',
                'x-ratelimit-remaining-requests' => '0',
                'x-ratelimit-reset-requests' => '1m',
                'retry-after' => '60',
            ]
        ),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using(Provider::Groq, 'llama-3.1-70b-versatile')
        ->withPrompt('This will trigger rate limiting')
        ->asStream();

    $exception = null;

    try {
        // Consume the generator to trigger the exception
        foreach ($response as $chunk) {
            // The test should throw before completing
        }
    } catch (\Prism\Prism\Exceptions\PrismRateLimitedException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(\Prism\Prism\Exceptions\PrismRateLimitedException::class);
});

it('handles empty stream response correctly', function (): void {
    Http::fake([
        '*' => Http::response(
            "data: [DONE]\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        ),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using(Provider::Groq, 'llama-3.1-70b-versatile')
        ->withPrompt('Empty response')
        ->asStream();

    $chunks = [];
    foreach ($response as $chunk) {
        $chunks[] = $chunk;
    }

    expect($chunks)->toBeArray();
    // Should be empty since only [DONE] was sent
});
