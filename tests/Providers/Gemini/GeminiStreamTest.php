<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\ProviderTool;
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
    expect($lastEvent->usage->promptTokens)->toBe(22)
        ->and($lastEvent->usage->completionTokens)->toBe(229)
        ->and($lastEvent->usage->thoughtTokens)->toBe(68)
        ->and($lastEvent->usage->cacheReadInputTokens)->toBeNull();
});

it('can generate text stream using tools ', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-with-tools-thought');

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
        ->using(Provider::Gemini, 'gemini-3-pro-preview')
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
        ->and($toolCalls[0]->reasoningId)->toBe('Eq0ECqoEAdHtim8E9iAxfKwT6QsjWgFpC3mNjNoEc0uf/khdTIkry0wbRzOTpYuw1HdLFm5263kddqUYf+HKlTGq5fbXQb8e+MyBxsft/WzOcmMKTGxbnW1Nx7JPsMhu9TQltjp0w+EIOd7CSJnIcubiZ13tzGR7MOF8OIzTXidrdtNWRRND8kYKIMIBbW2EWuE2CJUzihJFct9JQSQulq/WpJ1ctiI1bl89HcoIGXTuTNK90CncMw/+ink6edobepVG4umPGIdgx2B6bE9uchv+kjKWSwnDsY5hvUP/uSseFZ5fpZbsrhB3IAMVrLBtFTKiLkuvkUh664EQ91rgfYGJ2NTu3SwpEfLy3ftUxqI1d/t84lMWo9X0om5ihM4sFpD//DxGeEKbs3XtAPEJoWawy24aXoVQb59SSt23Yr87epA261b8a2pDPW7QnUCg4GWSquAZ8z39BxO3DJ4fyU72QpRzs9m3G5XYt5iV8+ndMHjJsIxmeXYqqteq3QCNLbAwKCBLbpq4HyYgyu7R4RpnUEx1t8/3seXPfhEUSaP5Prjr9TEwdOB/fgig2BV2eJ4AuAvbw4A7/RkkBhvUQ+0KW3HByDBN5g8X59K5S3fasUhcDRU4QsGQOh9DShH2bi+o71SWpRw5zdKT3AmdDEQqrg5ybVK+plpA6XLSmDIekNl4lqn0YsUzPtzCdvD0rlI1OP85jNnYwQeRS1Dbm8viYbGdZWjTehd+jK1xIxU=')
        ->and($toolResults)->not->toBeEmpty()
        ->and($toolResults[0]->result)->toBe('The weather will be 75° and sunny in San Francisco')
        ->and($text)->toContain('The current weather in San Francisco is 75°F and sunny. You likely won\'t need a coat, but you might want to bring a light jacket just in case it gets breezy or cools down later.');

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class)
        ->and($lastEvent->usage->toArray())->toBe([
            'prompt_tokens' => 409,
            'completion_tokens' => 154,
            'cache_write_input_tokens' => null,
            'cache_read_input_tokens' => null,
            'thought_tokens' => 95,
        ]);

    $stepUsage = collect($events)
        ->whereInstanceOf(StepFinishEvent::class)
        ->map(fn (StepFinishEvent $event): array => [$event->usage->promptTokens, $event->usage->completionTokens, $event->usage->thoughtTokens])
        ->values()
        ->all();

    expect($stepUsage)->toBe([[131, 110, 95], [278, 44, null]]);

    // Verify the HTTP request
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'streamGenerateContent?alt=sse')
        && isset($request->data()['contents']));
});

it('can generate text stream using file_search provider tool with options', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withPrompt('What are the main topics in the documents?')
        ->withProviderTools([
            new ProviderTool(
                type: 'file_search',
                name: 'file_search',
                options: [
                    'file_search_store_names' => ['fileSearchStores/test-store-456'],
                ]
            ),
        ])
        ->asStream();

    // Consume the stream
    foreach ($response as $event) {
        // Just consume events
    }

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        expect($data['tools'][0])->toHaveKey('file_search');
        expect($data['tools'][0]['file_search'])->toBeArray();
        expect($data['tools'][0]['file_search'])->toHaveKey('file_search_store_names');
        expect($data['tools'][0]['file_search']['file_search_store_names'])->toBe(['fileSearchStores/test-store-456']);

        return true;
    });
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

    $toolCallEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolCallEvent);
    $toolResultEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolResultEvent);
    $streamStartEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEndEvent);

    expect($toolCallEvents)->not->toBeEmpty();
    expect($toolResultEvents)->not->toBeEmpty();

    // Verify only one StreamStartEvent and one StreamEndEvent
    expect($streamStartEvents)->toHaveCount(1);
    expect($streamEndEvents)->toHaveCount(1);

    $firstToolCallEvent = array_values($toolCallEvents)[0];
    expect($firstToolCallEvent->toolCall)->not->toBeNull();
    // Verify reasoningId property exists and has a value
    expect($firstToolCallEvent->toolCall->reasoningId)->not->toBeNull()
        ->and($firstToolCallEvent->toolCall->reasoningId)->toBe('thought_abc123');

    $firstToolResultEvent = array_values($toolResultEvents)[0];
    expect($firstToolResultEvent->toolResult)->not->toBeNull();
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.0-flash')
        ->withPrompt('Explain how AI works')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    // Check for StepStartEvent after StreamStartEvent
    $stepStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent);
    expect($stepStartEvents)->toHaveCount(1);

    // Check for StepFinishEvent before StreamEndEvent
    $stepFinishEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent));
    expect($stepFinishEvents)->toHaveCount(1);

    // Verify StepFinishEvent contains usage data
    expect($stepFinishEvents[0]->usage)->not->toBeNull();
    expect($stepFinishEvents[0]->usage->promptTokens)->toBe(21);
    expect($stepFinishEvents[0]->usage->completionTokens)->toBe(47);

    // Verify order: StreamStart -> StepStart -> ... -> StepFinish -> StreamEnd
    $eventTypes = array_map(get_class(...), $events);
    $streamStartIndex = array_search(StreamStartEvent::class, $eventTypes);
    $stepStartIndex = array_search(StepStartEvent::class, $eventTypes);
    $stepFinishIndex = array_search(StepFinishEvent::class, $eventTypes);
    $streamEndIndex = array_search(StreamEndEvent::class, $eventTypes);

    expect($streamStartIndex)->toBeLessThan($stepStartIndex);
    expect($stepStartIndex)->toBeLessThan($stepFinishIndex);
    expect($stepFinishIndex)->toBeLessThan($streamEndIndex);
});

it('emits multiple step events with tool calls', function (): void {
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
        ->withPrompt('What is the weather in San Francisco?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    // With tool calls, we should have multiple step start/finish pairs
    $stepStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent);
    $stepFinishEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent);

    // At least 2 steps: one for tool call, one for final response
    expect(count($stepStartEvents))->toBeGreaterThanOrEqual(2);
    expect(count($stepFinishEvents))->toBeGreaterThanOrEqual(2);

    // Verify step start/finish pairs are balanced
    expect(count($stepStartEvents))->toBe(count($stepFinishEvents));
});

it('sends StreamEndEvent using tools with streaming and max steps = 1', function (): void {
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
        ->withMaxSteps(1)
        ->withPrompt('What is the weather in San Francisco?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect($events)->not->toBeEmpty();

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class)
        ->and($lastEvent->finishReason)->toBe(FinishReason::ToolCalls)
        ->and(collect($events)->whereInstanceOf(StreamEndEvent::class))->toHaveCount(1)
        ->and(collect($events)->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::ToolCalls);
});

it('can generate text stream using multiple parallel tool calls', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-with-multiple-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => 'The weather will be '.($city === 'San Francisco' ? 50 : 75)."° and sunny in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withTools($tools)
        ->withMaxSteps(5)
        ->withPrompt('Is it warmer in San Francisco or Santa Cruz? What\'s the weather like in both cities?')
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

    expect($events)->not->toBeEmpty()
        ->and($text)->not->toBeEmpty()
        ->and($toolCalls)->not->toBeEmpty()
        ->and(count($toolCalls))->toBe(2)
        ->and(count($toolResults))->toBe(2)
        ->and($text)->toContain('50')
        ->and($text)->toContain('75');

    // Both tool calls should have reasoningId from the first tool call's thoughtSignature
    expect($toolCalls[0]->reasoningId)->not->toBeNull();
    expect($toolCalls[1]->reasoningId)->not->toBeNull();
    expect($toolCalls[0]->reasoningId)->toBe($toolCalls[1]->reasoningId);
});

it('sends topK in generationConfig for streaming', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-basic-text');

    $events = [];
    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.0-flash')
        ->withPrompt('Explain how AI works')
        ->usingTopK(40)
        ->asStream();

    foreach ($response as $event) {
        $events[] = $event;
    }

    Http::assertSent(function (Request $request): true {
        $data = $request->data();

        expect($data['generationConfig'])
            ->toHaveKey('topK')
            ->and($data['generationConfig']['topK'])->toBe(40);

        return true;
    });
});

it('passes service_tier in the request body for streaming', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withPrompt('Summarize this document.')
        ->withProviderOptions(['serviceTier' => 'flex'])
        ->asStream();

    // Consume the stream
    foreach ($response as $event) {
        //
    }

    Http::assertSent(function (Request $request): true {
        $data = $request->data();

        expect($data)->toHaveKey('service_tier')
            ->and($data['service_tier'])->toBe('flex');

        return true;
    });
});

function geminiGatingSse(array ...$chunks): string
{
    return collect($chunks)
        ->map(fn (array $chunk): string => 'data: '.json_encode($chunk)."\n\n")
        ->implode('');
}

/**
 * @param  array<int, array<string, mixed>>  $parts
 * @return array<string, mixed>
 */
function geminiGatingChunk(array $parts, ?string $finishReason = null): array
{
    return [
        'candidates' => [array_filter([
            'content' => ['parts' => $parts, 'role' => 'model'],
            'finishReason' => $finishReason,
            'index' => 0,
        ], fn (mixed $value): bool => $value !== null)],
        'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
        'modelVersion' => 'gemini-2.5-flash',
    ];
}

/**
 * @return array<string, mixed>
 */
function geminiGatingFunctionCallChunk(?string $finishReason = null): array
{
    return geminiGatingChunk([['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Detroit']]]], $finishReason);
}

/**
 * @return Collection<int, StreamEvent>
 */
function geminiGatingStream(int &$calls, int $maxSteps = 3): Collection
{
    return collect(iterator_to_array(Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withTools([
            Tool::as('get_weather')
                ->for('Get the weather for a city')
                ->withStringParameter('city', 'The city')
                ->using(function (string $city) use (&$calls): string {
                    $calls++;

                    return "Sunny in {$city}";
                }),
        ])
        ->withMaxSteps($maxSteps)
        ->withPrompt('What is the weather in Detroit?')
        ->asStream(), false));
}

describe('tool execution gating', function (): void {
    it('never runs function calls when the turn does not complete normally', function (array $chunks, FinishReason $finishReason): void {
        Http::fake([
            '*' => Http::response(geminiGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = geminiGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(0)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe($finishReason)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe($finishReason);

        Http::assertSentCount(1);
    })->with([
        'MAX_TOKENS after the function call' => [[geminiGatingFunctionCallChunk(), geminiGatingChunk([['text' => '']], 'MAX_TOKENS')], FinishReason::Length],
        'MAX_TOKENS on the function call chunk' => [[geminiGatingFunctionCallChunk('MAX_TOKENS')], FinishReason::Length],
        'SAFETY after the function call' => [[geminiGatingFunctionCallChunk(), geminiGatingChunk([['text' => '']], 'SAFETY')], FinishReason::ContentFilter],
        'MALFORMED_FUNCTION_CALL on the function call chunk' => [[geminiGatingFunctionCallChunk('MALFORMED_FUNCTION_CALL')], FinishReason::ContentFilter],
        'stream ends without a finish reason' => [[geminiGatingFunctionCallChunk()], FinishReason::Stop],
    ]);

    it('runs function calls once when the turn completes normally', function (array $chunks): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(geminiGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream'])
                ->push(geminiGatingSse(geminiGatingChunk([['text' => 'Sunny in Detroit.']], 'STOP')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = geminiGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop]);

        Http::assertSentCount(2);
    })->with([
        'STOP on the function call chunk' => [[geminiGatingFunctionCallChunk('STOP')]],
        'STOP in a separate chunk' => [[geminiGatingFunctionCallChunk(), geminiGatingChunk([['text' => '']], 'STOP')]],
    ]);

    it('reports ToolCalls when max steps ends the stream on a tool call', function (array $chunks): void {
        Http::fake([
            '*' => Http::response(geminiGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = geminiGatingStream($calls, maxSteps: 1);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($events->last())->toBeInstanceOf(StreamEndEvent::class);

        Http::assertSentCount(1);
    })->with([
        'STOP on the function call chunk' => [[geminiGatingFunctionCallChunk('STOP')]],
        'STOP in a separate chunk' => [[geminiGatingFunctionCallChunk(), geminiGatingChunk([['text' => '']], 'STOP')]],
    ]);
});

describe('content blocks', function (): void {
    it('completes the thinking and text a step started before its tool calls', function (array $chunks): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(geminiGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream'])
                ->push(geminiGatingSse(geminiGatingChunk([['text' => 'Sunny in Detroit.']], 'STOP')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = geminiGatingStream($calls);

        expect($events->map(fn (StreamEvent $event): string => $event::class)->values()->all())->toBe([
            StreamStartEvent::class,
            StepStartEvent::class,
            ThinkingStartEvent::class,
            ThinkingEvent::class,
            ThinkingCompleteEvent::class,
            TextStartEvent::class,
            TextDeltaEvent::class,
            TextCompleteEvent::class,
            ToolCallEvent::class,
            ToolResultEvent::class,
            StepFinishEvent::class,
            StepStartEvent::class,
            TextStartEvent::class,
            TextDeltaEvent::class,
            TextCompleteEvent::class,
            StepFinishEvent::class,
            StreamEndEvent::class,
        ])
            ->and($events->whereInstanceOf(ThinkingCompleteEvent::class)->sole()->reasoningId)->toBe($events->whereInstanceOf(ThinkingStartEvent::class)->sole()->reasoningId)
            ->and($events->whereInstanceOf(TextDeltaEvent::class)->map->delta->values()->all())->toBe(['Let me check.', 'Sunny in Detroit.'])
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())->toBe([FinishReason::ToolCalls, FinishReason::Stop]);
    })->with([
        'STOP on the function call chunk' => [[
            geminiGatingChunk([['text' => 'The user wants the weather.', 'thought' => true]]),
            geminiGatingChunk([['text' => 'Let me check.']]),
            geminiGatingFunctionCallChunk('STOP'),
        ]],
        'STOP in a separate chunk' => [[
            geminiGatingChunk([['text' => 'The user wants the weather.', 'thought' => true]]),
            geminiGatingChunk([['text' => 'Let me check.']]),
            geminiGatingFunctionCallChunk(),
            geminiGatingChunk([['text' => '']], 'STOP'),
        ]],
    ]);

    it('completes the thinking and text a step started when the turn stops on a function call', function (): void {
        Http::fake([
            '*' => Http::response(geminiGatingSse(
                geminiGatingChunk([['text' => 'The user wants the weather.', 'thought' => true]]),
                geminiGatingChunk([['text' => 'Let me check.']]),
                geminiGatingFunctionCallChunk('MAX_TOKENS'),
            ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = geminiGatingStream($calls);

        expect($calls)->toBe(0)
            ->and($events->map(fn (StreamEvent $event): string => $event::class)->values()->all())->toBe([
                StreamStartEvent::class,
                StepStartEvent::class,
                ThinkingStartEvent::class,
                ThinkingEvent::class,
                ThinkingCompleteEvent::class,
                TextStartEvent::class,
                TextDeltaEvent::class,
                TextCompleteEvent::class,
                ToolCallEvent::class,
                StepFinishEvent::class,
                StreamEndEvent::class,
            ])
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::Length);
    });
});

/**
 * @param  array<int, array<string, mixed>>  $parts
 * @param  array<string, int>|null  $usageMetadata
 * @return array<string, mixed>
 */
function geminiUsageChunk(array $parts, ?string $finishReason = null, ?array $usageMetadata = null): array
{
    return array_filter([
        'candidates' => [array_filter([
            'content' => ['parts' => $parts, 'role' => 'model'],
            'finishReason' => $finishReason,
            'index' => 0,
        ], fn (mixed $value): bool => $value !== null)],
        'usageMetadata' => $usageMetadata,
        'modelVersion' => 'gemini-2.5-flash',
    ], fn (mixed $value): bool => $value !== null);
}

/**
 * @return array<string, mixed>
 */
function geminiUsageFunctionCallChunk(?string $finishReason, ?array $usageMetadata): array
{
    return geminiUsageChunk([['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Detroit']]]], $finishReason, $usageMetadata);
}

describe('usage', function (): void {
    it('keeps the latest cumulative snapshot and ignores a trailing chunk without usage metadata', function (): void {
        Http::fake([
            '*' => Http::response(geminiGatingSse(
                geminiUsageChunk([['text' => 'Sunny']], null, ['promptTokenCount' => 100, 'cachedContentTokenCount' => 40, 'candidatesTokenCount' => 5, 'totalTokenCount' => 105]),
                geminiUsageChunk([['text' => ' in Detroit.']], null, ['promptTokenCount' => 100, 'cachedContentTokenCount' => 40, 'toolUsePromptTokenCount' => 7, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 30, 'totalTokenCount' => 157]),
                geminiUsageChunk([['text' => '']], 'STOP'),
            ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $events = collect(iterator_to_array(Prism::text()
            ->using(Provider::Gemini, 'gemini-2.5-flash')
            ->withPrompt('What is the weather in Detroit?')
            ->asStream(), false));

        $expected = [
            'prompt_tokens' => 67,
            'completion_tokens' => 50,
            'cache_write_input_tokens' => null,
            'cache_read_input_tokens' => 40,
            'thought_tokens' => 30,
        ];

        expect($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe($expected)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->usage->toArray())->toBe($expected);
    });

    it('keeps reported zeros and leaves unreported fields null', function (): void {
        Http::fake([
            '*' => Http::response(geminiGatingSse(
                geminiUsageChunk([['text' => 'Hi']], 'STOP', ['promptTokenCount' => 9, 'cachedContentTokenCount' => 0, 'candidatesTokenCount' => 2, 'thoughtsTokenCount' => 0, 'totalTokenCount' => 11]),
            ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $usage = collect(iterator_to_array(Prism::text()
            ->using(Provider::Gemini, 'gemini-2.5-flash')
            ->withPrompt('Hi')
            ->asStream(), false))->whereInstanceOf(StreamEndEvent::class)->sole()->usage;

        expect($usage->cacheReadInputTokens)->toBe(0)
            ->and($usage->thoughtTokens)->toBe(0)
            ->and($usage->cacheWriteInputTokens)->toBeNull()
            ->and($usage->promptTokens)->toBe(9)
            ->and($usage->completionTokens)->toBe(2);
    });

    it('sums every step once and reads usage that trails a tool call finish', function (): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(geminiGatingSse(
                    geminiUsageFunctionCallChunk('STOP', ['promptTokenCount' => 50, 'candidatesTokenCount' => 3, 'totalTokenCount' => 53]),
                    ['usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 6, 'thoughtsTokenCount' => 12, 'totalTokenCount' => 68], 'modelVersion' => 'gemini-2.5-flash'],
                ), 200, ['Content-Type' => 'text/event-stream'])
                ->push(geminiGatingSse(
                    geminiUsageChunk([['text' => 'Sunny in Detroit.']], 'STOP', ['promptTokenCount' => 80, 'cachedContentTokenCount' => 32, 'candidatesTokenCount' => 9, 'totalTokenCount' => 89]),
                ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = geminiGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
                'prompt_tokens' => 98,
                'completion_tokens' => 27,
                'cache_write_input_tokens' => null,
                'cache_read_input_tokens' => 32,
                'thought_tokens' => 12,
            ])
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map(fn (StepFinishEvent $event): array => $event->usage->toArray())->values()->all())->toBe([
                ['prompt_tokens' => 50, 'completion_tokens' => 18, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => null, 'thought_tokens' => 12],
                ['prompt_tokens' => 48, 'completion_tokens' => 9, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 32, 'thought_tokens' => null],
            ]);
    });

    it('reports every step when max steps ends the stream on a tool call', function (): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(geminiGatingSse(geminiUsageFunctionCallChunk('STOP', ['promptTokenCount' => 50, 'candidatesTokenCount' => 3, 'totalTokenCount' => 53])), 200, ['Content-Type' => 'text/event-stream'])
                ->push(geminiGatingSse(geminiUsageFunctionCallChunk('STOP', ['promptTokenCount' => 70, 'candidatesTokenCount' => 4, 'thoughtsTokenCount' => 5, 'totalTokenCount' => 79])), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $events = collect(iterator_to_array(Prism::text()
            ->using(Provider::Gemini, 'gemini-2.5-flash')
            ->withTools([Tool::as('get_weather')->for('Get the weather')->withStringParameter('city', 'The city')->using(fn (string $city): string => "Sunny in {$city}")])
            ->withMaxSteps(2)
            ->withPrompt('What is the weather in Detroit?')
            ->asStream(), false));

        expect($events->whereInstanceOf(StreamEndEvent::class))->toHaveCount(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
                'prompt_tokens' => 120,
                'completion_tokens' => 12,
                'cache_write_input_tokens' => null,
                'cache_read_input_tokens' => null,
                'thought_tokens' => 5,
            ]);

        Http::assertSentCount(2);
    });
});
