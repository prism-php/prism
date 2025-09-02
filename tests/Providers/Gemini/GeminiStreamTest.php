<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', env('GEMINI_API_KEY', 'sss-1234567890'));
});

it('can generate text stream with a basic prompt', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.0-flash')
        ->withPrompt('Explain how AI works')
        ->asStream();

    $text = '';
    $chunks = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;
        $text .= $chunk->text;

        // Verify usage information for each chunk
        expect($chunk->usage)->not->toBeNull()
            ->and($chunk->usage->promptTokens)->toBeGreaterThanOrEqual(0)
            ->and($chunk->usage->completionTokens)->toBeGreaterThanOrEqual(0);
    }

    expect($chunks)
        ->not->toBeEmpty()
        ->and($text)->not->toBeEmpty()
        ->and($text)->toContain(
            'AI? It\'s simple! We just feed a computer a HUGE pile of information, tell it to find patterns, and then it pretends to be smart! Like teaching a parrot to say cool things. Mostly magic, though.'
        )
        ->and($chunks[0]->usage->promptTokens)->toBe(21)
        ->and($chunks[0]->usage->completionTokens)->toBe(0)
        ->and($chunks[3]->usage->promptTokens)->toBe(21)  // Last chunk
        ->and($chunks[3]->usage->completionTokens)->toBe(47);  // Completion tokens in last chunk

    // Verify the HTTP request
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'streamGenerateContent?alt=sse')
        && isset($request->data()['contents']));
});

it('can generate text stream using searchGrounding', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-with-tools-search-grounding');

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withProviderOptions(['searchGrounding' => true])
        ->withMaxSteps(4)
        ->withPrompt('What\'s the current weather in San Francisco? And tell me if I need to wear a coat?')
        ->asStream();

    $text = '';
    $chunks = [];
    $toolResults = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;

        if ($chunk->toolCalls !== []) {
            expect($chunk->toolCalls[0]->name)->not
                ->toBeEmpty()
                ->and($chunk->toolCalls[0]->arguments())->toBeArray();
        }

        if ($chunk->toolResults !== []) {
            $toolResults = array_merge($toolResults, $chunk->toolResults);
        }

        $text .= $chunk->text;
    }

    // Verify that the request was sent with the correct tools configuration
    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        // Verify the endpoint is for streaming
        $endpointCorrect = str_contains($request->url(), 'streamGenerateContent?alt=sse');

        // Verify tools configuration has google_search when searchGrounding is true
        $hasGoogleSearch = isset($data['tools']) &&
            isset($data['tools'][0]['google_search']) &&
            $data['tools'][0]['google_search'] instanceof \stdClass;

        // Verify tools are configured as expected (google_search, not function_declarations)
        $toolsConfigCorrect = ! isset($data['tools'][0]['function_declarations']);

        return $endpointCorrect && $hasGoogleSearch && $toolsConfigCorrect;
    });

    expect($chunks)
        ->not->toBeEmpty()
        ->and($chunks)->not->toBeEmpty()
        ->and($text)->toContain('The current weather in San Francisco is cloudy with a temperature of 56°F (13°C), and it feels like 54°F (12°C). There\'s a 0% chance of rain currently, though light rain is forecast for today and tonight with a 20% chance.')
        ->and($chunks[0]->usage->promptTokens)->toBe(22)
        ->and($chunks[0]->usage->completionTokens)->toBe(27);
});

it('can generate text stream using tools ', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-with-tools');

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
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What\'s the current weather in San Francisco? And tell me if I need to wear a coat?')
        ->asStream();

    $text = '';
    $chunks = [];
    $toolCalls = [];
    $toolResults = [];
    $meta = null;

    foreach ($response as $chunk) {
        $chunks[] = $chunk;
        $text .= $chunk->text;
        if ($chunk->chunkType === ChunkType::ToolCall) {
            $toolCalls = array_merge($toolCalls, $chunk->toolCalls);
        }
        if ($chunk->chunkType === ChunkType::ToolResult) {
            $toolResults = array_merge($toolResults, $chunk->toolResults);
        }
        dump($chunk);
    }

    expect($chunks)
        ->not->toBeEmpty()
        ->and($text)->not->toBeEmpty()
        ->and($toolCalls)->not->toBeEmpty()
        ->and($toolCalls[0]->name)->toBe('weather')
        ->and($toolCalls[0]->arguments())->toBe(['city' => 'San Francisco'])
        ->and($toolResults)->not->toBeEmpty()
        ->and($toolResults[0]->result)->toBe('The weather will be 75° and sunny in San Francisco')
        ->and($text)->toContain('It is 75° and sunny in San Francisco, so you likely do not need to wear a coat.')
        ->and(last($chunks)->usage->promptTokens)->toBe(159)
        ->and(last($chunks)->usage->completionTokens)->toBe(22);

    // Verify the HTTP request
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'streamGenerateContent?alt=sse')
        && isset($request->data()['contents']));
});

it('yields ToolCall chunks before ToolResult chunks', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-with-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What\'s the current weather in San Francisco?')
        ->asStream();

    $chunks = [];
    $chunkOrder = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;
        if ($chunk->chunkType === ChunkType::ToolCall) {
            $chunkOrder[] = 'ToolCall';
        }
        if ($chunk->chunkType === ChunkType::ToolResult) {
            $chunkOrder[] = 'ToolResult';
        }
    }

    expect($chunkOrder)
        ->not->toBeEmpty()
        ->and($chunkOrder[0])->toBe('ToolCall')
        ->and($chunkOrder[1])->toBe('ToolResult');

    $toolCallChunks = array_filter($chunks, fn (\Prism\Prism\Text\Chunk $chunk): bool => $chunk->chunkType === ChunkType::ToolCall);
    $toolResultChunks = array_filter($chunks, fn (\Prism\Prism\Text\Chunk $chunk): bool => $chunk->chunkType === ChunkType::ToolResult);

    expect($toolCallChunks)->not->toBeEmpty();
    expect($toolResultChunks)->not->toBeEmpty();

    $firstToolCall = array_values($toolCallChunks)[0];
    expect($firstToolCall->toolCalls)->not->toBeEmpty();
    expect($firstToolCall->toolResults)->toBeEmpty();

    $firstToolResult = array_values($toolResultChunks)[0];
    expect($firstToolResult->toolCalls)->toBeEmpty();
    expect($firstToolResult->toolResults)->not->toBeEmpty();
});
