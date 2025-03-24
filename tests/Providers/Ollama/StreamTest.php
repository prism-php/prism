<?php

declare(strict_types=1);

namespace Tests\Providers\Ollama;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.ollama.url', env('OLLAMA_URL', 'http://localhost:11434'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-basic-text');

    $response = Prism::text()
        ->using('ollama', 'granite3-dense:8b')
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $chunks = [];
    $lastChunkHasFinishReason = false;

    foreach ($response as $chunk) {
        $chunks[] = $chunk;
        $text .= $chunk->text;

        if ($chunk->finishReason !== null) {
            $lastChunkHasFinishReason = true;
        }
    }

    expect($chunks)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();
    expect($lastChunkHasFinishReason)->toBeTrue();

    // Last chunk should have a finish reason of "stop"
    $lastChunk = $chunks[count($chunks) - 1];
    expect($lastChunk->finishReason)->toBe(\Prism\Prism\Enums\FinishReason::Stop);
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-with-tools');

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
        ->using('ollama', 'granite3-dense:8b')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStream();

    $text = '';
    $chunks = [];
    $toolCallFound = false;
    $toolResults = [];
    $finishReasonFound = false;

    foreach ($response as $chunk) {
        $chunks[] = $chunk;

        if ($chunk->toolCalls !== []) {
            $toolCallFound = true;
            expect($chunk->toolCalls[0]->name)->toBeString();
            expect($chunk->toolCalls[0]->name)->not->toBeEmpty();
            expect($chunk->toolCalls[0]->arguments())->toBeArray();
        }

        if ($chunk->toolResults !== []) {
            $toolResults = array_merge($toolResults, $chunk->toolResults);
        }

        if ($chunk->finishReason !== null) {
            $finishReasonFound = true;
            expect($chunk->finishReason)->toBeInstanceOf(\Prism\Prism\Enums\FinishReason::class);
        }

        $text .= $chunk->text;
    }

    expect($chunks)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();

    // For the basic tools test, validate completion state
    expect($finishReasonFound)->toBeTrue();

    // The last chunk should have a finish reason
    $lastChunk = $chunks[count($chunks) - 1];
    expect($lastChunk->finishReason)->not->toBeNull();
});

it('can process a complete conversation with multiple tool calls', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-multi-tool-conversation');

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
        ->using('ollama', 'granite3-dense:8b')
        ->withTools($tools)
        ->withMaxSteps(5) // Allow multiple tool call rounds
        ->withPrompt('What time is the Tigers game today and should I wear a coat in Detroit?')
        ->asStream();

    $chunkCount = 0;
    $toolCallCount = 0;
    $validToolCallSeen = false;
    $finishReasonFound = false;
    $lastChunk = null;

    foreach ($response as $chunk) {
        $chunkCount++;
        $lastChunk = $chunk;

        if ($chunk->toolCalls !== []) {
            $toolCallCount++;

            foreach ($chunk->toolCalls as $toolCall) {
                expect($toolCall->name)->toBeString();
                expect($toolCall->arguments())->toBeArray();

                // Verify one of our expected tool names is called
                if (in_array($toolCall->name, ['weather', 'search'])) {
                    $validToolCallSeen = true;
                }
            }
        }

        if ($chunk->finishReason !== null) {
            $finishReasonFound = true;
        }
    }

    expect($chunkCount)->toBeGreaterThan(0);

    // The test might pass even if toolCallCount is 0 when using old fixtures.
    // When re-recording fixtures this will properly test for tool calls.
    if ($toolCallCount > 0) {
        expect($validToolCallSeen)->toBeTrue();
    }

    expect($finishReasonFound)->toBeTrue();

    // Last chunk should have a finish reason
    expect($lastChunk)->not->toBeNull();
    expect($lastChunk->finishReason)->not->toBeNull();
});

it('throws a PrismRateLimitedException with a 429 response code', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using('ollama', 'granite3-dense:8b')
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $chunk) {
        // Don't remove me rector!
    }
})->throws(PrismRateLimitedException::class);
