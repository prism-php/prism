<?php

declare(strict_types=1);

namespace Tests\Providers\Z;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.z.api_key', env('Z_API_KEY', 'zai-123'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $events = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($events)->not->toBeEmpty()
        ->and($text)->not->toBeEmpty();

    $eventTypes = array_map(get_class(...), $events);

    expect($eventTypes)->toContain(StreamStartEvent::class)
        ->and($eventTypes)->toContain(StreamEndEvent::class)
        ->and(count(array_filter($eventTypes, fn (string $t): bool => $t === TextDeltaEvent::class)))
        ->toBeGreaterThan(0);

    $lastEvent = end($events);

    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class)
        ->and($lastEvent->finishReason)->toBeInstanceOf(FinishReason::class)
        ->and($lastEvent->usage->toArray())->toBe([
            'prompt_tokens' => 7,
            'completion_tokens' => 163,
            'cache_write_input_tokens' => null,
            'cache_read_input_tokens' => null,
            'thought_tokens' => null,
        ]);

    $streamStartEvent = $events[array_search(StreamStartEvent::class, $eventTypes)];

    expect($streamStartEvent)->toBeInstanceOf(StreamStartEvent::class)
        ->and($streamStartEvent->model)->toBe('glm-5')
        ->and($streamStartEvent->provider)->toBe('z');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.z.ai/api/paas/v4/chat/completions'
            && $body['stream'] === true
            && $body['model'] === 'glm-5';
    });
});

it('keeps content delivered in the first chunk of the stream', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-content-in-first-chunk');

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withPrompt('Say hello')
        ->asStream();

    $text = '';

    foreach ($response as $event) {
        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($text)->toBe('Hello there');
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-with-tools');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want weather for')
            ->using(fn (string $city): string => "The weather in {$city} will be 75° and sunny"),

        Tool::as('search_games')
            ->for('useful for searching current games times in city')
            ->withStringParameter('city', 'The city that you want game times for')
            ->using(fn (string $city): string => "The tigers game is at 3pm in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat? please check all the details from tools')
        ->asStream();

    $events = [];
    $toolCallEvents = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof ToolCallEvent) {
            $toolCallEvents[] = $event;
            expect($event->toolCall->name)
                ->toBeString()
                ->and($event->toolCall->name)->not->toBeEmpty();
        }

        if ($event instanceof ToolResultEvent) {
            expect($event->toolResult->result)->not->toBeEmpty();
        }
    }

    expect($events)->not->toBeEmpty()
        ->and($toolCallEvents)->not->toBeEmpty();

    $streamStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEndEvent);

    expect($streamStartEvents)->toHaveCount(1)
        ->and($streamEndEvents)->toHaveCount(1)
        ->and(array_values($streamEndEvents)[0]->usage->toArray())->toBe([
            'prompt_tokens' => 72,
            'completion_tokens' => 319,
            'cache_write_input_tokens' => null,
            'cache_read_input_tokens' => 544,
            'thought_tokens' => null,
        ])
        ->and(collect($events)->whereInstanceOf(StepFinishEvent::class)->map(fn (StepFinishEvent $event): array => [
            $event->usage->promptTokens,
            $event->usage->cacheReadInputTokens,
            $event->usage->completionTokens,
        ])->values()->all())->toBe([[1, 271, 210], [71, 273, 109]]);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.z.ai/api/paas/v4/chat/completions'
            && isset($body['tools'])
            && $body['stream'] === true
            && $body['model'] === 'glm-5';
    });
});

it('replays the streamed tool calls alongside their results on the next step', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-with-tools');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want weather for')
            ->using(fn (string $city): string => "The weather in {$city} will be 75° and sunny"),
        Tool::as('search_games')
            ->for('useful for searching current games times in city')
            ->withStringParameter('city', 'The city that you want game times for')
            ->using(fn (string $city): string => "The tigers game is at 3pm in {$city}"),
    ];

    collect(Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat?')
        ->asStream())->all();

    $messages = collect(Http::recorded()[1][0]->data()['messages']);
    $assistant = $messages->firstWhere('role', 'assistant');

    expect(collect($assistant['tool_calls'] ?? [])->map(fn (array $toolCall): array => [
        $toolCall['id'],
        $toolCall['type'],
        $toolCall['function']['name'],
        json_decode((string) $toolCall['function']['arguments'], true),
    ])->all())->toBe([
        ['call_-8062812703858712887', 'function', 'search_games', ['city' => 'Detroit']],
        ['call_-8062812703858712886', 'function', 'get_weather', ['city' => 'Detroit']],
    ])
        ->and($messages->where('role', 'tool')->pluck('tool_call_id')->values()->all())->toBe([
            'call_-8062812703858712887',
            'call_-8062812703858712886',
        ]);
});

it('handles max_tokens parameter correctly', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-max-tokens');

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withMaxTokens(1000)
        ->withPrompt('Who are you?')
        ->asStream();

    /** @phpstan-ignore-next-line */
    collect($response);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.z.ai/api/paas/v4/chat/completions'
            && $body['max_tokens'] === 1000;
    });
});

it('handles system prompts correctly', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-system-prompt');

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withSystemPrompt('You are a helpful assistant.')
        ->withPrompt('Who are you?')
        ->asStream();

    /** @phpstan-ignore-next-line */
    collect($response);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return count($body['messages']) === 2
            && $body['messages'][0]['role'] === 'system'
            && $body['messages'][1]['role'] === 'user';
    });
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withPrompt('Who are you?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    $stepStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent);

    expect($stepStartEvents)->toHaveCount(1);

    $stepFinishEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent);

    expect($stepFinishEvents)->toHaveCount(1);

    $eventTypes = array_map(get_class(...), $events);
    $streamStartIndex = array_search(StreamStartEvent::class, $eventTypes);
    $stepStartIndex = array_search(StepStartEvent::class, $eventTypes);
    $stepFinishIndex = array_search(StepFinishEvent::class, $eventTypes);
    $streamEndIndex = array_search(StreamEndEvent::class, $eventTypes);

    expect($streamStartIndex)->toBeLessThan($stepStartIndex)
        ->and($stepStartIndex)->toBeLessThan($stepFinishIndex)
        ->and($stepFinishIndex)->toBeLessThan($streamEndIndex);
});

it('emits multiple step events with tool calls', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-with-tools');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want weather for')
            ->using(fn (string $city): string => "The weather in {$city} will be 75° and sunny"),

        Tool::as('search_games')
            ->for('useful for searching current games times in city')
            ->withStringParameter('city', 'The city that you want game times for')
            ->using(fn (string $city): string => "The tigers game is at 3pm in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What is weather in Detroit?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    $stepStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent);
    $stepFinishEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent);

    expect(count($stepStartEvents))->toBeGreaterThanOrEqual(2)
        ->and(count($stepFinishEvents))->toBeGreaterThanOrEqual(2)
        ->and(count($stepStartEvents))->toBe(count($stepFinishEvents));
});

it('sends StreamEndEvent using tools with streaming and max steps = 1', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-with-tools');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want weather for')
            ->using(fn (string $city): string => "The weather in {$city} will be 75° and sunny"),

        Tool::as('search_games')
            ->for('useful for searching current games times in city')
            ->withStringParameter('city', 'The city that you want game times for')
            ->using(fn (string $city): string => "The tigers game is at 3pm in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withTools($tools)
        ->withMaxSteps(1)
        ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat? please check all the details from tools')
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

it('handles specific tool choice in streaming', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-with-required-tool-call');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
    ];

    $response = Prism::text()
        ->using(Provider::Z, 'z-model')
        ->withPrompt('Do something')
        ->withTools($tools)
        ->withToolChoice(ToolChoice::Auto)
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect($events)->not->toBeEmpty();

    $toolCallEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof ToolCallEvent));

    expect($toolCallEvents)->not->toBeEmpty()
        ->and($toolCallEvents[0]->toolCall->name)->toBeIn(['weather', 'search']);
});

it('throws PrismRateLimitedException for 429 in streaming', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using(Provider::Z, 'z-model')
        ->withPrompt('Who are you?')
        ->asStream();

    /** @phpstan-ignore-next-line */
    collect($response);

})->throws(PrismRateLimitedException::class);

function zGatingSse(array ...$chunks): string
{
    return collect($chunks)
        ->map(fn (array $chunk): string => 'data: '.json_encode($chunk)."\n\n")
        ->implode('')."data: [DONE]\n\n";
}

/**
 * @return array<string, mixed>
 */
function zGatingToolChunk(?string $finishReason = null): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'glm-4.6',
        'choices' => [[
            'index' => 0,
            'delta' => [
                'role' => 'assistant',
                'tool_calls' => [[
                    'index' => 0,
                    'id' => 'call_gating',
                    'type' => 'function',
                    'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Detroit"}'],
                ]],
            ],
            'finish_reason' => $finishReason,
        ]],
    ];
}

/**
 * @return array<string, mixed>
 */
function zGatingFinishChunk(string $finishReason): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'glm-4.6',
        'choices' => [['index' => 0, 'delta' => ['content' => ''], 'finish_reason' => $finishReason]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ];
}

/**
 * @return Collection<int, StreamEvent>
 */
function zGatingStream(int &$calls, int $maxSteps = 3): Collection
{
    return collect(iterator_to_array(Prism::text()
        ->using('z', 'glm-4.6')
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
    it('never runs buffered tool calls when the step does not finish with tool calls', function (array $chunks, FinishReason $finishReason): void {
        Http::fake([
            '*' => Http::response(zGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = zGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(0)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe($finishReason)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe($finishReason);

        Http::assertSentCount(1);
    })->with([
        'length after the tool call' => [[zGatingToolChunk(), zGatingFinishChunk('length')], FinishReason::Length],
        'length on the tool call chunk' => [[zGatingToolChunk('length')], FinishReason::Length],
        'stream ends without a finish reason' => [[zGatingToolChunk()], FinishReason::Stop],
    ]);

    it('runs tool calls once when the step finishes with tool calls', function (array $chunks): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(zGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream'])
                ->push(zGatingSse(zGatingFinishChunk('stop')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = zGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop]);

        Http::assertSentCount(2);
    })->with([
        'tool_calls in a separate chunk' => [[zGatingToolChunk(), zGatingFinishChunk('tool_calls')]],
        'tool_calls on the tool call chunk' => [[zGatingToolChunk('tool_calls')]],
    ]);

    it('reports ToolCalls when max steps ends the stream on a tool call', function (array $chunks): void {
        Http::fake([
            '*' => Http::response(zGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = zGatingStream($calls, maxSteps: 1);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($events->last())->toBeInstanceOf(StreamEndEvent::class);

        Http::assertSentCount(1);
    })->with([
        'tool_calls in a separate chunk' => [[zGatingToolChunk(), zGatingFinishChunk('tool_calls')]],
        'tool_calls on the tool call chunk' => [[zGatingToolChunk('tool_calls')]],
    ]);
});

/**
 * @param  array<string, mixed>  $delta
 * @return array<string, mixed>
 */
function zGatingDeltaChunk(array $delta, ?string $finishReason = null): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'glm-4.6',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
    ];
}

describe('tool execution gating with mixed chunks', function (): void {
    it('runs tool calls once when the tool-call finish arrives on a chunk with other deltas', function (array $delta, string $streamedDelta): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(zGatingSse(zGatingToolChunk(), zGatingDeltaChunk($delta, 'tool_calls')), 200, ['Content-Type' => 'text/event-stream'])
                ->push(zGatingSse(zGatingFinishChunk('stop')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = zGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop])
            ->and($events->whereInstanceOf(TextDeltaEvent::class)->map->delta->implode(''))->toBe($streamedDelta);

        Http::assertSentCount(2);
    })->with([
        'content' => [['content' => 'Checking the weather.'], 'Checking the weather.'],
    ]);

    it('never runs tool calls when a non-tool finish arrives on a chunk with other deltas', function (array $delta, string $streamedDelta): void {
        Http::fake([
            '*' => Http::response(zGatingSse(zGatingToolChunk(), zGatingDeltaChunk($delta, 'length')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = zGatingStream($calls);

        expect($calls)->toBe(0)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Length)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::Length)
            ->and($events->whereInstanceOf(TextDeltaEvent::class)->map->delta->implode(''))->toBe($streamedDelta);

        Http::assertSentCount(1);
    })->with([
        'content' => [['content' => 'Checking the weather.'], 'Checking the weather.'],
    ]);

    it('ends with Stop when the step after a tool call has no finish reason', function (): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(zGatingSse(zGatingToolChunk(), zGatingFinishChunk('tool_calls')), 200, ['Content-Type' => 'text/event-stream'])
                ->push(zGatingSse(zGatingDeltaChunk(['content' => 'Sunny in Detroit.'])), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = zGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop]);

        Http::assertSentCount(2);
    });
});

describe('usage snapshots', function (): void {
    it('reads usage from the last chunk when it also carries content and the finish reason', function (): void {
        Http::fake([
            '*' => Http::response(zGatingSse([
                'id' => 'chatcmpl-usage',
                'model' => 'glm-4.6',
                'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Sunny.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 300, 'total_tokens' => 1500, 'prompt_tokens_details' => ['cached_tokens' => 800]],
            ]), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = zGatingStream($calls);
        $expected = ['prompt_tokens' => 400, 'completion_tokens' => 300, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 800, 'thought_tokens' => null];

        expect($events->whereInstanceOf(TextDeltaEvent::class)->map->delta->implode(''))->toBe('Sunny.')
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->usage->toArray())->toBe($expected)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe($expected);
    });

    it('reads usage from a tool-call chunk that finishes the step and counts each step once', function (): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(zGatingSse([
                    ...zGatingToolChunk('tool_calls'),
                    'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 40, 'total_tokens' => 340, 'prompt_tokens_details' => ['cached_tokens' => 0]],
                ]), 200, ['Content-Type' => 'text/event-stream'])
                ->push(zGatingSse([
                    ...zGatingFinishChunk('stop'),
                    'usage' => ['prompt_tokens' => 360, 'completion_tokens' => 20, 'total_tokens' => 380, 'prompt_tokens_details' => ['cached_tokens' => 300]],
                ]), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = zGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map(fn (StepFinishEvent $event): array => $event->usage->toArray())->values()->all())->toBe([
                ['prompt_tokens' => 300, 'completion_tokens' => 40, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 0, 'thought_tokens' => null],
                ['prompt_tokens' => 60, 'completion_tokens' => 20, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 300, 'thought_tokens' => null],
            ])
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
                'prompt_tokens' => 360,
                'completion_tokens' => 60,
                'cache_write_input_tokens' => null,
                'cache_read_input_tokens' => 300,
                'thought_tokens' => null,
            ]);
    });

    it('counts the usage of a tool step once when max steps ends the stream on it', function (): void {
        Http::fake([
            '*' => Http::response(zGatingSse([
                ...zGatingToolChunk('tool_calls'),
                'usage' => ['prompt_tokens' => 238, 'completion_tokens' => 117, 'total_tokens' => 355, 'prompt_tokens_details' => ['cached_tokens' => 43]],
            ]), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $events = collect(iterator_to_array(Prism::text()
            ->using('z', 'glm-4.6')
            ->withTools([Tool::as('get_weather')->for('Get the weather')->withStringParameter('city', 'The city')->using(fn (string $city): string => "Sunny in {$city}")])
            ->withMaxSteps(1)
            ->withPrompt('What is the weather in Detroit?')
            ->asStream(), false));

        $expected = ['prompt_tokens' => 195, 'completion_tokens' => 117, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 43, 'thought_tokens' => null];

        expect($events->whereInstanceOf(StepFinishEvent::class)->sole()->usage->toArray())->toBe($expected)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe($expected);
    });
});

describe('content blocks', function (): void {
    it('completes every text block it starts exactly once', function (array $responses, array $expected): void {
        $sequence = Http::sequence();

        foreach ($responses as $chunks) {
            $sequence->push(zGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']);
        }

        Http::fake(['*' => $sequence])->preventStrayRequests();

        $calls = 0;

        expect(zGatingStream($calls)->map(fn (StreamEvent $event): string => class_basename($event))->values()->all())->toBe($expected);
    })->with([
        'text before tool calls' => [
            [
                [zGatingDeltaChunk(['content' => 'Let me check.']), zGatingToolChunk(), zGatingFinishChunk('tool_calls')],
                [zGatingFinishChunk('stop')],
            ],
            ['StreamStartEvent', 'StepStartEvent', 'TextStartEvent', 'TextDeltaEvent', 'TextCompleteEvent', 'ToolCallEvent', 'ToolResultEvent', 'StepFinishEvent', 'StepStartEvent', 'StepFinishEvent', 'StreamEndEvent'],
        ],
        'text before a tool call cut off by length' => [
            [[zGatingDeltaChunk(['content' => 'Let me check.']), zGatingToolChunk('length')]],
            ['StreamStartEvent', 'StepStartEvent', 'TextStartEvent', 'TextDeltaEvent', 'TextCompleteEvent', 'StepFinishEvent', 'StreamEndEvent'],
        ],
        'text when the stream closes early' => [
            [[zGatingDeltaChunk(['content' => 'Sunny.'])]],
            ['StreamStartEvent', 'StepStartEvent', 'TextStartEvent', 'TextDeltaEvent', 'TextCompleteEvent', 'StepFinishEvent', 'StreamEndEvent'],
        ],
    ]);
});

/**
 * @param  array<string, mixed>  $delta
 * @return array<string, mixed>
 */
function zMixedToolChunk(array $delta, ?string $finishReason = null): array
{
    return zGatingDeltaChunk([
        ...$delta,
        'tool_calls' => zGatingToolChunk()['choices'][0]['delta']['tool_calls'],
    ], $finishReason);
}

dataset('z mixed tool chunk deltas', [
    'text' => [['content' => 'Let me check.'], ['TextStartEvent', 'TextDeltaEvent', 'TextCompleteEvent'], 'Let me check.'],
]);

describe('chunks that carry text and a tool call together', function (): void {
    it('streams the text before the tool call, runs the tool once and replays the text', function (array $delta, array $blocks, string $text, bool $finishOnMixedChunk): void {
        $chunks = $finishOnMixedChunk
            ? [zMixedToolChunk($delta, 'tool_calls')]
            : [zMixedToolChunk($delta), zGatingFinishChunk('tool_calls')];

        Http::fake([
            '*' => Http::sequence()
                ->push(zGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream'])
                ->push(zGatingSse(zGatingFinishChunk('stop')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = zGatingStream($calls);
        $replayed = collect(Http::recorded()[1][0]->data()['messages'])->firstWhere('role', 'assistant');

        expect($events->map(fn (StreamEvent $event): string => class_basename($event))->values()->all())->toBe([
            'StreamStartEvent', 'StepStartEvent', ...$blocks, 'ToolCallEvent', 'ToolResultEvent', 'StepFinishEvent', 'StepStartEvent', 'StepFinishEvent', 'StreamEndEvent',
        ])
            ->and($events->whereInstanceOf(TextDeltaEvent::class)->map->delta->implode(''))->toBe($text)
            ->and($calls)->toBe(1)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())->toBe([FinishReason::ToolCalls, FinishReason::Stop])
            ->and($replayed['content'] ?? '')->toBe($text);

        Http::assertSentCount(2);
    })->with('z mixed tool chunk deltas')->with([
        'tool_calls finish on a later chunk' => [false],
        'tool_calls finish on the same chunk' => [true],
    ]);

    it('keeps the text and never runs the tool when the chunk ends with a non-tool finish', function (array $delta, array $blocks, string $text): void {
        Http::fake([
            '*' => Http::response(zGatingSse(zMixedToolChunk($delta, 'length')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = zGatingStream($calls);

        expect($events->map(fn (StreamEvent $event): string => class_basename($event))->values()->all())->toBe([
            'StreamStartEvent', 'StepStartEvent', ...$blocks, 'StepFinishEvent', 'StreamEndEvent',
        ])
            ->and($events->whereInstanceOf(TextDeltaEvent::class)->map->delta->implode(''))->toBe($text)
            ->and($calls)->toBe(0)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::Length)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Length);

        Http::assertSentCount(1);
    })->with('z mixed tool chunk deltas');
});
