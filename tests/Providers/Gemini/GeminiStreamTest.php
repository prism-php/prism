<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', env('GEMINI_API_KEY', 'sss-1234567890'));
});

it('can generate text stream with a basic prompt', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-basic-text');

    $origModel = 'gemini-2.0-flash';
    $response = Prism::text()
        ->using(Provider::Gemini, $origModel)
        ->withPrompt('Explain how AI works')
        ->asStream();

    $text = '';
    $events = [];
    $model = null;

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof StreamStartEvent) {
            $model = $event->model;
        }

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($events)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);

    expect($model)->toEqual($origModel);

    expect($text)->toContain(
        'AI? It\'s simple! We just feed a computer a HUGE pile of information, tell it to find patterns, and then it pretends to be smart! Like teaching a parrot to say cool things. Mostly magic, though.'
    );

    // Verify usage information in the final event
    expect($lastEvent->usage)
        ->not->toBeNull()
        ->and($lastEvent->usage->promptTokens)->toBe(21)
        ->and($lastEvent->usage->completionTokens)->toBe(47);

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
    $events = [];
    $toolCalls = [];
    $toolResults = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }

        if ($event instanceof ToolCallEvent) {
            $toolCalls[] = $event->toolCall;
            expect($event->toolCall->name)->not->toBeEmpty();
        }

        if ($event instanceof ToolResultEvent) {
            $toolResults[] = $event->toolResult;
        }
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

    expect($events)->not->toBeEmpty();
    expect($text)->toContain('The current weather in San Francisco is cloudy with a temperature of 56°F (13°C), and it feels like 54°F (12°C). There\'s a 0% chance of rain currently, though light rain is forecast for today and tonight with a 20% chance.');

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->usage->promptTokens)->toBe(22);
    expect($lastEvent->usage->completionTokens)->toBe(161);
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
    $events = [];
    $toolCalls = [];
    $toolResults = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }

        if ($event instanceof ToolCallEvent) {
            $toolCalls[] = $event->toolCall;
        }

        if ($event instanceof ToolResultEvent) {
            $toolResults[] = $event->toolResult;
        }
    }

    expect($events)
        ->not->toBeEmpty()
        ->and($text)->not->toBeEmpty()
        ->and($toolCalls)->not->toBeEmpty()
        ->and($toolCalls[0]->name)->toBe('weather')
        ->and($toolCalls[0]->arguments())->toBe(['city' => 'San Francisco'])
        ->and($toolCalls[0]->reasoningId)->not->toBeNull()
        ->and($toolCalls[0]->reasoningId)->toBe('thought_abc123')
        ->and($toolResults)->not->toBeEmpty()
        ->and($toolResults[0]->result)->toBe(['result' => 'The weather will be 75° and sunny in San Francisco'])
        ->and($text)->toContain('It is 75° and sunny in San Francisco, so you likely do not need to wear a coat.');

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->usage->promptTokens)->toBe(159);
    expect($lastEvent->usage->completionTokens)->toBe(22);

    // Verify the HTTP request
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'streamGenerateContent?alt=sse')
        && isset($request->data()['contents']));
});

it('yields ToolCall events before ToolResult events', function (): void {
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

    $events = [];
    $eventOrder = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof ToolCallEvent) {
            $eventOrder[] = 'ToolCall';
        }

        if ($event instanceof ToolResultEvent) {
            $eventOrder[] = 'ToolResult';
        }
    }

    expect($eventOrder)
        ->not->toBeEmpty()
        ->and($eventOrder[0])->toBe('ToolCall')
        ->and($eventOrder[1])->toBe('ToolResult');

    $toolCallEvents = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $event): bool => $event instanceof ToolCallEvent);
    $toolResultEvents = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $event): bool => $event instanceof ToolResultEvent);

    expect($toolCallEvents)->not->toBeEmpty();
    expect($toolResultEvents)->not->toBeEmpty();

    $firstToolCallEvent = array_values($toolCallEvents)[0];
    expect($firstToolCallEvent->toolCall)->not->toBeNull();
    // Verify reasoningId property exists and has a value
    expect($firstToolCallEvent->toolCall->reasoningId)->not->toBeNull()
        ->and($firstToolCallEvent->toolCall->reasoningId)->toBe('thought_abc123');

    $firstToolResultEvent = array_values($toolResultEvents)[0];
    expect($firstToolResultEvent->toolResult)->not->toBeNull();
});
