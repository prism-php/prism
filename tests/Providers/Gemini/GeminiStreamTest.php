<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
    }

    expect($chunks)
        ->not->toBeEmpty()
        ->and($text)->not->toBeEmpty()
        ->and($text)->toContain(
            'AI? It\'s simple! We just feed a computer a HUGE pile of information, tell it to find patterns, and then it pretends to be smart! Like teaching a parrot to say cool things. Mostly magic, though.'
        );

    // Verify the HTTP request
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'streamGenerateContent?alt=sse')
        && isset($request->data()['contents']));
});

it('can generate text stream using searchGrounding', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-with-tools');

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.0-flash')
        ->withProviderMeta(Provider::Gemini, ['searchGrounding' => true])
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

    expect($chunks)->not
        ->toBeEmpty()
        ->and($chunks)->not
        ->toBeEmpty()
        ->and($text)
        ->toContain('The weather in San Francisco is currently 58Â°F (14Â°C) and partly cloudy. It feels like 55Â°F (13Â°C) with 79% humidity. There is a 0% chance of rain right now but showers are expected to develop.');
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
        ->using(Provider::Gemini, 'gemini-2.0-flash')
        ->withTools($tools)
        ->withPrompt('What\'s the current weather in San Francisco? And tell me if I need to wear a coat?')
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
        ->and($text)->toContain('The weather in San Francisco is currently 58Â°F (14Â°C) and partly cloudy. It feels like 55Â°F (13Â°C) with 79% humidity. There is a 0% chance of rain right now but showers are expected to develop.')
        ->and($text)->toContain('a light jacket or coat would be advisable');

    // Verify the HTTP request
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'streamGenerateContent?alt=sse')
        && isset($request->data()['contents']));
});
