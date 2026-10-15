<?php

declare(strict_types=1);

namespace Tests\Providers\Mistral;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.mistral.api_key', env('MISTRAL_API_KEY', 'test-key-12345'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-basic-text-1');

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
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

    expect($events)
        ->not->toBeEmpty()
        ->and($text)->not->toBeEmpty()
        ->and($text)->toContain(
            'large language model'
        );

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-with-tools-1');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),

        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The Tigers game today is at 3pm in Detroit.'),
    ];

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-large-latest')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withMessages([
            new UserMessage('What time is the tigers game today and should I wear a coat?'),
        ])
        ->asStream();

    $text = '';
    $events = [];
    $toolCallEvents = [];
    $toolResultEvents = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }

        if ($event instanceof ToolCallEvent) {
            $toolCallEvents[] = $event;
            expect($event->toolCall->name)
                ->toBeString()
                ->and($event->toolCall->name)->not
                ->toBeEmpty()
                ->and($event->toolCall->arguments())->toBeArray();
        }

        if ($event instanceof ToolResultEvent) {
            $toolResultEvents[] = $event;
        }

        if ($event instanceof StreamEndEvent) {
            expect($event->finishReason)->toBeInstanceOf(FinishReason::class);
        }
    }

    expect($events)->not->toBeEmpty();
    expect($toolCallEvents)->not->toBeEmpty();
    expect($toolResultEvents)->not->toBeEmpty();

    // Verify only one StreamStartEvent and one StreamEndEvent
    $streamStartEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEndEvent);
    expect($streamStartEvents)->toHaveCount(1);
    expect($streamEndEvents)->toHaveCount(1);

    expect(array_values($streamEndEvents)[0]->usage->toArray())->toBe([
        'prompt_tokens' => 381,
        'completion_tokens' => 80,
        'cache_write_input_tokens' => null,
        'cache_read_input_tokens' => null,
        'thought_tokens' => null,
    ]);

    $stepUsage = collect($events)
        ->whereInstanceOf(StepFinishEvent::class)
        ->map(fn (StepFinishEvent $event): array => [$event->usage->promptTokens, $event->usage->completionTokens])
        ->values()
        ->all();

    expect($stepUsage)->toBe([[164, 25], [217, 55]]);
});

it('handles maximum tool call depth exceeded', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-with-tools-1');

    $tools = [
        Tool::as('weather')
            ->for('A tool that will be called recursively')
            ->withStringParameter('input', 'Any input')
            ->using(fn (string $input): string => 'This is a recursive response that will trigger another tool call.'),
    ];

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-large-latest')
        ->withTools($tools)
        ->withMaxSteps(0)
        ->withPrompt('Call the weather tool multiple times')
        ->asStream();

    $exception = null;

    try {
        foreach ($response as $chunk) {
            // The test should throw before completing
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
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('This will trigger invalid JSON')
        ->asStream();

    $exception = null;

    try {
        foreach ($response as $chunk) {
            // The test should throw before completing
        }
    } catch (PrismStreamDecodeException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(PrismStreamDecodeException::class);
});

it('respects system prompts in the requests', function (): void {
    Http::fake([
        '*' => Http::response(
            "data: {\"choices\": [{\"delta\": {\"content\": \"Hello\"}}]}\n\ndata: {\"choices\": [{\"delta\": {\"content\": \" world\"}, \"finish_reason\": \"stop\"}]}\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        ),
    ])->preventStrayRequests();

    $systemPrompt = 'You are a helpful assistant.';

    Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withSystemPrompt($systemPrompt)
        ->withPrompt('Say hello')
        ->asStream()
        ->current();

    Http::assertSent(function ($request) use ($systemPrompt): bool {
        $data = $request->data();

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
    Http::fake([
        '*' => Http::response(
            "data: {\"choices\": [{\"delta\": {\"content\": \"Hello\"}}]}\n\ndata: {\"choices\": [{\"delta\": {\"content\": \" world\"}, \"finish_reason\": \"stop\"}]}\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        ),
    ])->preventStrayRequests();

    Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('Test')
        ->asStream()
        ->current();

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        expect($data['stream'])->toBe(true);
        expect($data['model'])->toBe('mistral-small-latest');
        expect($data['messages'])->toBeArray();
        expect($data['messages'][0]['role'])->toBe('user');
        expect($data['messages'][0]['content'])->toBeArray();
        expect($data['messages'][0]['content'][0]['type'])->toBe('text');
        expect($data['messages'][0]['content'][0]['text'])->toBe('Test');

        return true;
    });
});

it('forwards reasoning_effort provider option for streaming requests', function (): void {
    Http::fake([
        '*' => Http::response(
            "data: {\"choices\": [{\"delta\": {\"content\": \"Hello\"}}]}\n\ndata: {\"choices\": [{\"delta\": {\"content\": \" world\"}, \"finish_reason\": \"stop\"}]}\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        ),
    ])->preventStrayRequests();

    Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('Test')
        ->withProviderOptions([
            'reasoning_effort' => 'high',
        ])
        ->asStream()
        ->current();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return isset($data['reasoning_effort'])
            && $data['reasoning_effort'] === 'high';
    });
});

it('can handle event types correctly', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-with-tools-1');

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
        ->using(Provider::Mistral, 'mistral-large-latest')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('Get weather for Detroit')
        ->asStream();

    $hasToolCallEvent = false;
    $hasToolResultEvent = false;

    foreach ($response as $event) {
        if ($event instanceof ToolCallEvent) {
            $hasToolCallEvent = true;
        }

        if ($event instanceof ToolResultEvent) {
            $hasToolResultEvent = true;
        }
    }

    expect($hasToolCallEvent)->toBe(true);
    expect($hasToolResultEvent)->toBe(true);
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
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('This will trigger rate limiting')
        ->asStream();

    $exception = null;

    try {
        foreach ($response as $chunk) {
            // The test should throw before completing
        }
    } catch (PrismRateLimitedException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(PrismRateLimitedException::class);
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
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('Empty response')
        ->asStream();

    $chunks = [];
    foreach ($response as $chunk) {
        $chunks[] = $chunk;
    }

    expect($chunks)->toBeArray();
});

it('includes correct token counts in StreamEndEvent', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-basic-text-1');

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('Who are you?')
        ->asStream();

    $streamEndEvent = null;

    foreach ($response as $event) {
        if ($event instanceof StreamEndEvent) {
            $streamEndEvent = $event;
        }
    }

    expect($streamEndEvent)->not->toBeNull();
    expect($streamEndEvent->usage)->not->toBeNull();
    expect($streamEndEvent->usage->promptTokens)->toBe(7);
    expect($streamEndEvent->usage->completionTokens)->toBe(13);
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-basic-text-1');

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('Who are you?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    // Check for StepStartEvent after StreamStartEvent
    $stepStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent);
    expect($stepStartEvents)->toHaveCount(1);

    // Check for StepFinishEvent before StreamEndEvent
    $stepFinishEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent);
    expect($stepFinishEvents)->toHaveCount(1);

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
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-with-tools-1');

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
        ->using(Provider::Mistral, 'mistral-large-latest')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What is the weather in Detroit?')
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
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-with-tools-1');

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
        ->using(Provider::Mistral, 'mistral-large-latest')
        ->withTools($tools)
        ->withMaxSteps(1)
        ->withPrompt('What is the weather in Detroit?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect($events)->not->toBeEmpty();

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class)
        ->and($lastEvent->usage->promptTokens)->toBe(164)
        ->and($lastEvent->usage->completionTokens)->toBe(25)
        ->and($lastEvent->finishReason)->toBe(FinishReason::ToolCalls)
        ->and(collect($events)->whereInstanceOf(StreamEndEvent::class))->toHaveCount(1)
        ->and(collect($events)->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::ToolCalls);
});

it('can stream with reasoning models', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-reasoning-model-1');

    $response = Prism::text()
        ->using(Provider::Mistral, 'magistral-small-latest')
        ->withPrompt('What is 2+2?')
        ->asStream();

    $text = '';
    $thinking = '';
    $events = [];
    $thinkingStartEvents = [];
    $thinkingEvents = [];
    $thinkingCompleteEvents = [];
    $textStartEvents = [];
    $textDeltaEvents = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof ThinkingStartEvent) {
            $thinkingStartEvents[] = $event;
        }

        if ($event instanceof ThinkingEvent) {
            $thinkingEvents[] = $event;
            $thinking .= $event->delta;
        }

        if ($event instanceof ThinkingCompleteEvent) {
            $thinkingCompleteEvents[] = $event;
        }

        if ($event instanceof TextStartEvent) {
            $textStartEvents[] = $event;
        }

        if ($event instanceof TextDeltaEvent) {
            $textDeltaEvents[] = $event;
            $text .= $event->delta;
        }
    }

    // Verify thinking events
    expect($thinkingStartEvents)->toHaveCount(1)
        ->and($thinkingEvents)->not->toBeEmpty()
        ->and($thinkingCompleteEvents)->toHaveCount(1)
        ->and($thinking)->not->toBeEmpty();

    // Verify text events
    expect($textStartEvents)->toHaveCount(1)
        ->and($textDeltaEvents)->not->toBeEmpty()
        ->and($text)->not->toBeEmpty();

    // Verify event ordering: ThinkingStart -> ThinkingEvents -> ThinkingComplete -> TextStart -> TextDelta
    $eventTypes = array_map(get_class(...), $events);
    $thinkingStartIndex = array_search(ThinkingStartEvent::class, $eventTypes);
    $thinkingCompleteIndex = array_search(ThinkingCompleteEvent::class, $eventTypes);
    $textStartIndex = array_search(TextStartEvent::class, $eventTypes);

    expect($thinkingStartIndex)->toBeLessThan($thinkingCompleteIndex)
        ->and($thinkingCompleteIndex)->toBeLessThan($textStartIndex);

    // Verify stream ends properly
    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);

    // The legacy num_cached_tokens (64 here, against 10 prompt tokens) is not read as a cache hit
    expect($lastEvent->usage->toArray())->toBe([
        'prompt_tokens' => 10,
        'completion_tokens' => 142,
        'cache_write_input_tokens' => null,
        'cache_read_input_tokens' => null,
        'thought_tokens' => null,
    ]);
});

function mistralGatingSse(array ...$chunks): string
{
    return collect($chunks)
        ->map(fn (array $chunk): string => 'data: '.json_encode($chunk)."\n\n")
        ->implode('')."data: [DONE]\n\n";
}

/**
 * @return array<string, mixed>
 */
function mistralGatingToolChunk(?string $finishReason = null): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'mistral-large-latest',
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
function mistralGatingFinishChunk(string $finishReason): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'mistral-large-latest',
        'choices' => [['index' => 0, 'delta' => ['content' => ''], 'finish_reason' => $finishReason]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ];
}

/**
 * @return Collection<int, StreamEvent>
 */
function mistralGatingStream(int &$calls, int $maxSteps = 3): Collection
{
    return collect(iterator_to_array(Prism::text()
        ->using('mistral', 'mistral-large-latest')
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
            '*' => Http::response(mistralGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = mistralGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(0)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe($finishReason)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe($finishReason);

        Http::assertSentCount(1);
    })->with([
        'length after the tool call' => [[mistralGatingToolChunk(), mistralGatingFinishChunk('length')], FinishReason::Length],
        'length on the tool call chunk' => [[mistralGatingToolChunk('length')], FinishReason::Length],
        'content filter after the tool call' => [[mistralGatingToolChunk(), mistralGatingFinishChunk('content_filter')], FinishReason::ContentFilter],
        'stream ends without a finish reason' => [[mistralGatingToolChunk()], FinishReason::Stop],
    ]);

    it('runs tool calls once when the step finishes with tool calls', function (array $chunks): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(mistralGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream'])
                ->push(mistralGatingSse(mistralGatingFinishChunk('stop')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = mistralGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop]);

        Http::assertSentCount(2);
    })->with([
        'tool_calls in a separate chunk' => [[mistralGatingToolChunk(), mistralGatingFinishChunk('tool_calls')]],
        'tool_calls on the tool call chunk' => [[mistralGatingToolChunk('tool_calls')]],
    ]);

    it('reports ToolCalls when max steps ends the stream on a tool call', function (array $chunks): void {
        Http::fake([
            '*' => Http::response(mistralGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = mistralGatingStream($calls, maxSteps: 1);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($events->last())->toBeInstanceOf(StreamEndEvent::class);

        Http::assertSentCount(1);
    })->with([
        'tool_calls in a separate chunk' => [[mistralGatingToolChunk(), mistralGatingFinishChunk('tool_calls')]],
        'tool_calls on the tool call chunk' => [[mistralGatingToolChunk('tool_calls')]],
    ]);
});

/**
 * @param  array<string, mixed>  $delta
 * @return array<string, mixed>
 */
function mistralGatingDeltaChunk(array $delta, ?string $finishReason = null): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'mistral-large-latest',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
    ];
}

describe('tool execution gating with mixed chunks', function (): void {
    it('runs tool calls once when the tool-call finish arrives on a chunk with other deltas', function (array $delta, string $streamedDelta): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(mistralGatingSse(mistralGatingToolChunk(), mistralGatingDeltaChunk($delta, 'tool_calls')), 200, ['Content-Type' => 'text/event-stream'])
                ->push(mistralGatingSse(mistralGatingFinishChunk('stop')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = mistralGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop])
            ->and($events->whereInstanceOf([TextDeltaEvent::class, ThinkingEvent::class])->map->delta->implode(''))->toBe($streamedDelta);

        Http::assertSentCount(2);
    })->with([
        'content' => [['content' => 'Checking the weather.'], 'Checking the weather.'],
        'thinking' => [['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Thinking it over.']]]]], 'Thinking it over.'],
    ]);

    it('never runs tool calls when a non-tool finish arrives on a chunk with other deltas', function (array $delta, string $streamedDelta): void {
        Http::fake([
            '*' => Http::response(mistralGatingSse(mistralGatingToolChunk(), mistralGatingDeltaChunk($delta, 'length')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = mistralGatingStream($calls);

        expect($calls)->toBe(0)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Length)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::Length)
            ->and($events->whereInstanceOf([TextDeltaEvent::class, ThinkingEvent::class])->map->delta->implode(''))->toBe($streamedDelta);

        Http::assertSentCount(1);
    })->with([
        'content' => [['content' => 'Checking the weather.'], 'Checking the weather.'],
        'thinking' => [['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Thinking it over.']]]]], 'Thinking it over.'],
    ]);

    it('ends with Stop when the step after a tool call has no finish reason', function (): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(mistralGatingSse(mistralGatingToolChunk(), mistralGatingFinishChunk('tool_calls')), 200, ['Content-Type' => 'text/event-stream'])
                ->push(mistralGatingSse(mistralGatingDeltaChunk(['content' => 'Sunny in Detroit.'])), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = mistralGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop]);

        Http::assertSentCount(2);
    });
});

describe('usage', function (): void {
    it('takes cached input out of the prompt and keeps reasoning inside the completion', function (): void {
        Http::fake([
            '*' => Http::response(mistralGatingSse(
                mistralGatingDeltaChunk(['role' => 'assistant', 'content' => '8']),
                [
                    'id' => 'chatcmpl-usage',
                    'model' => 'mistral-small-latest',
                    'choices' => [['index' => 0, 'delta' => ['content' => '573'], 'finish_reason' => 'stop']],
                    'usage' => [
                        'prompt_tokens' => 12777,
                        'total_tokens' => 12889,
                        'completion_tokens' => 112,
                        'prompt_tokens_details' => ['cached_tokens' => 12752],
                        'completion_tokens_details' => ['reasoning_tokens' => 105],
                    ],
                ],
            ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $events = collect(iterator_to_array(Prism::text()
            ->using(Provider::Mistral, 'mistral-small-latest')
            ->withPrompt('What is the code of Fact 17?')
            ->asStream(), false));

        $expected = [
            'prompt_tokens' => 25,
            'completion_tokens' => 112,
            'cache_write_input_tokens' => null,
            'cache_read_input_tokens' => 12752,
            'thought_tokens' => 105,
        ];

        expect($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe($expected)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->usage->toArray())->toBe($expected);
    });

    it('sums each step once and reads usage that trails a tool call finish', function (): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(mistralGatingSse(
                    mistralGatingToolChunk('tool_calls'),
                    [
                        'id' => 'chatcmpl-gating',
                        'model' => 'mistral-large-latest',
                        'choices' => [],
                        'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 20, 'total_tokens' => 220, 'prompt_tokens_details' => ['cached_tokens' => 128]],
                    ],
                ), 200, ['Content-Type' => 'text/event-stream'])
                ->push(mistralGatingSse(
                    [
                        'id' => 'chatcmpl-gating',
                        'model' => 'mistral-large-latest',
                        'choices' => [['index' => 0, 'delta' => ['content' => 'Sunny.'], 'finish_reason' => 'stop']],
                        'usage' => ['prompt_tokens' => 240, 'completion_tokens' => 9, 'total_tokens' => 249, 'prompt_tokens_details' => ['cached_tokens' => 0]],
                    ],
                ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = mistralGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
                'prompt_tokens' => 312,
                'completion_tokens' => 29,
                'cache_write_input_tokens' => null,
                'cache_read_input_tokens' => 128,
                'thought_tokens' => null,
            ])
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map(fn (StepFinishEvent $event): array => $event->usage->toArray())->values()->all())->toBe([
                ['prompt_tokens' => 72, 'completion_tokens' => 20, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 128, 'thought_tokens' => null],
                ['prompt_tokens' => 240, 'completion_tokens' => 9, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 0, 'thought_tokens' => null],
            ]);
    });
});

describe('content blocks', function (): void {
    it('completes every text and thinking block it starts exactly once', function (array $responses, array $expected): void {
        $sequence = Http::sequence();

        foreach ($responses as $chunks) {
            $sequence->push(mistralGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']);
        }

        Http::fake(['*' => $sequence])->preventStrayRequests();

        $calls = 0;

        expect(mistralGatingStream($calls)->map(fn (StreamEvent $event): string => class_basename($event))->values()->all())->toBe($expected);
    })->with([
        'thinking before tool calls' => [
            [
                [mistralGatingDeltaChunk(['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Thinking it over.']]]]]), mistralGatingToolChunk('tool_calls')],
                [mistralGatingFinishChunk('stop')],
            ],
            ['StreamStartEvent', 'StepStartEvent', 'ThinkingStartEvent', 'ThinkingEvent', 'ThinkingCompleteEvent', 'ToolCallEvent', 'ToolResultEvent', 'StepFinishEvent', 'StepStartEvent', 'StepFinishEvent', 'StreamEndEvent'],
        ],
        'thinking and text before tool calls' => [
            [
                [
                    mistralGatingDeltaChunk(['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Thinking it over.']]]]]),
                    mistralGatingDeltaChunk(['content' => 'Let me check.']),
                    mistralGatingToolChunk(),
                    mistralGatingFinishChunk('tool_calls'),
                ],
                [mistralGatingFinishChunk('stop')],
            ],
            ['StreamStartEvent', 'StepStartEvent', 'ThinkingStartEvent', 'ThinkingEvent', 'ThinkingCompleteEvent', 'TextStartEvent', 'TextDeltaEvent', 'TextCompleteEvent', 'ToolCallEvent', 'ToolResultEvent', 'StepFinishEvent', 'StepStartEvent', 'StepFinishEvent', 'StreamEndEvent'],
        ],
        'text before a tool call cut off by length' => [
            [[mistralGatingDeltaChunk(['content' => 'Let me check.']), mistralGatingToolChunk('length')]],
            ['StreamStartEvent', 'StepStartEvent', 'TextStartEvent', 'TextDeltaEvent', 'TextCompleteEvent', 'StepFinishEvent', 'StreamEndEvent'],
        ],
        'thinking when the stream closes early' => [
            [[mistralGatingDeltaChunk(['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Thinking it over.']]]]])]],
            ['StreamStartEvent', 'StepStartEvent', 'ThinkingStartEvent', 'ThinkingEvent', 'ThinkingCompleteEvent', 'StepFinishEvent', 'StreamEndEvent'],
        ],
        'text when the stream closes early' => [
            [[mistralGatingDeltaChunk(['content' => 'Sunny.'])]],
            ['StreamStartEvent', 'StepStartEvent', 'TextStartEvent', 'TextDeltaEvent', 'TextCompleteEvent', 'StepFinishEvent', 'StreamEndEvent'],
        ],
    ]);
});
