<?php

declare(strict_types=1);

namespace Tests\Providers\Groq;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
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
            'Hello! I\'m Groq AI, your incredibly fast and efficient AI assistant. I\'m here to help you with any questions you might have. How can I assist you today?'
        );

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
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
    expect($text)->not->toBeEmpty();
    expect($toolCallEvents)->not->toBeEmpty();
    expect($toolResultEvents)->not->toBeEmpty();

    // Verify only one StreamStartEvent and one StreamEndEvent
    $streamStartEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEndEvent);
    expect($streamStartEvents)->toHaveCount(1);
    expect($streamEndEvents)->toHaveCount(1);

    // Only the final recording reports usage
    expect(array_values($streamEndEvents)[0]->usage->promptTokens)->toBe(65)
        ->and(array_values($streamEndEvents)[0]->usage->completionTokens)->toBe(142);
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
    } catch (PrismStreamDecodeException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(PrismStreamDecodeException::class);
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

it('can handle event types correctly', function (): void {
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

    $hasToolCallEvent = false;
    $hasToolResultEvent = false;
    $hasTextEvent = false;

    foreach ($response as $event) {
        if ($event instanceof ToolCallEvent) {
            $hasToolCallEvent = true;
        }

        if ($event instanceof ToolResultEvent) {
            $hasToolResultEvent = true;
        }

        if ($event instanceof TextDeltaEvent) {
            $hasTextEvent = true;
        }
    }

    expect($hasToolCallEvent)->toBe(true);
    expect($hasToolResultEvent)->toBe(true);
    expect($hasTextEvent)->toBe(true);
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

it('includes correct token counts in StreamEndEvent', function (): void {
    FixtureResponse::fakeStreamResponses('openai/v1/chat/completions', 'groq/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Groq, 'llama-3.1-70b-versatile')
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
    expect($streamEndEvent->usage->completionTokens)->toBe(50);
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeStreamResponses('openai/v1/chat/completions', 'groq/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Groq, 'llama-3.1-70b-versatile')
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
        ->and($lastEvent->finishReason)->toBe(FinishReason::ToolCalls)
        ->and(collect($events)->whereInstanceOf(StreamEndEvent::class))->toHaveCount(1)
        ->and(collect($events)->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::ToolCalls);
});

function groqGatingSse(array ...$chunks): string
{
    return collect($chunks)
        ->map(fn (array $chunk): string => 'data: '.json_encode($chunk)."\n\n")
        ->implode('')."data: [DONE]\n\n";
}

/**
 * @return array<string, mixed>
 */
function groqGatingToolChunk(?string $finishReason = null): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'llama-3.3-70b-versatile',
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
function groqGatingFinishChunk(string $finishReason): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [['index' => 0, 'delta' => ['content' => ''], 'finish_reason' => $finishReason]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ];
}

/**
 * @return Collection<int, StreamEvent>
 */
function groqGatingStream(int &$calls, int $maxSteps = 3): Collection
{
    return collect(iterator_to_array(Prism::text()
        ->using('groq', 'llama-3.3-70b-versatile')
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
            '*' => Http::response(groqGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = groqGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(0)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe($finishReason)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe($finishReason);

        Http::assertSentCount(1);
    })->with([
        'length after the tool call' => [[groqGatingToolChunk(), groqGatingFinishChunk('length')], FinishReason::Length],
        'length on the tool call chunk' => [[groqGatingToolChunk('length')], FinishReason::Length],
        'content filter after the tool call' => [[groqGatingToolChunk(), groqGatingFinishChunk('content_filter')], FinishReason::ContentFilter],
        'stream ends without a finish reason' => [[groqGatingToolChunk()], FinishReason::Stop],
    ]);

    it('runs tool calls once when the step finishes with tool calls', function (array $chunks): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(groqGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream'])
                ->push(groqGatingSse(groqGatingFinishChunk('stop')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = groqGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop]);

        Http::assertSentCount(2);
    })->with([
        'tool_calls in a separate chunk' => [[groqGatingToolChunk(), groqGatingFinishChunk('tool_calls')]],
        'tool_calls on the tool call chunk' => [[groqGatingToolChunk('tool_calls')]],
    ]);

    it('reports ToolCalls when max steps ends the stream on a tool call', function (array $chunks): void {
        Http::fake([
            '*' => Http::response(groqGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = groqGatingStream($calls, maxSteps: 1);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($events->last())->toBeInstanceOf(StreamEndEvent::class);

        Http::assertSentCount(1);
    })->with([
        'tool_calls in a separate chunk' => [[groqGatingToolChunk(), groqGatingFinishChunk('tool_calls')]],
        'tool_calls on the tool call chunk' => [[groqGatingToolChunk('tool_calls')]],
    ]);
});

/**
 * @param  array<string, mixed>  $delta
 * @return array<string, mixed>
 */
function groqGatingDeltaChunk(array $delta, ?string $finishReason = null): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
    ];
}

describe('tool execution gating with mixed chunks', function (): void {
    it('runs tool calls once when the tool-call finish arrives on a chunk with other deltas', function (array $delta, string $streamedDelta): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(groqGatingSse(groqGatingToolChunk(), groqGatingDeltaChunk($delta, 'tool_calls')), 200, ['Content-Type' => 'text/event-stream'])
                ->push(groqGatingSse(groqGatingFinishChunk('stop')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = groqGatingStream($calls);

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
            '*' => Http::response(groqGatingSse(groqGatingToolChunk(), groqGatingDeltaChunk($delta, 'length')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = groqGatingStream($calls);

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
                ->push(groqGatingSse(groqGatingToolChunk(), groqGatingFinishChunk('tool_calls')), 200, ['Content-Type' => 'text/event-stream'])
                ->push(groqGatingSse(groqGatingDeltaChunk(['content' => 'Sunny in Detroit.'])), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = groqGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop]);

        Http::assertSentCount(2);
    });
});

describe('usage', function (): void {
    it('counts usage once when Groq repeats it under x_groq and in a trailing chunk', function (): void {
        FixtureResponse::fakeStreamResponses('openai/v1/chat/completions', 'groq/stream-gpt-oss-repeated-usage');

        $events = collect(iterator_to_array(Prism::text()
            ->using(Provider::Groq, 'openai/gpt-oss-120b')
            ->withPrompt('What is the code of Fact 17?')
            ->asStream(), false));

        $expected = [
            'prompt_tokens' => 3131,
            'completion_tokens' => 70,
            'cache_write_input_tokens' => null,
            'cache_read_input_tokens' => null,
            'thought_tokens' => 59,
        ];

        expect($events->whereInstanceOf(StreamEndEvent::class))->toHaveCount(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe($expected)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->usage->toArray())->toBe($expected);
    });

    it('reads x_groq.usage when the chunk has no top-level usage', function (): void {
        Http::fake([
            '*' => Http::response(groqGatingSse(
                groqGatingDeltaChunk(['role' => 'assistant', 'content' => 'Hi']),
                [
                    'id' => 'chatcmpl-usage',
                    'model' => 'openai/gpt-oss-120b',
                    'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']],
                    'x_groq' => ['id' => 'req_usage', 'usage' => [
                        'prompt_tokens' => 4641,
                        'completion_tokens' => 1817,
                        'total_tokens' => 6458,
                        'prompt_tokens_details' => ['cached_tokens' => 4608],
                        'completion_tokens_details' => ['reasoning_tokens' => 1200],
                    ]],
                ],
            ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $usage = collect(iterator_to_array(Prism::text()
            ->using(Provider::Groq, 'openai/gpt-oss-120b')
            ->withPrompt('Hi')
            ->asStream(), false))->whereInstanceOf(StreamEndEvent::class)->sole()->usage;

        expect($usage->toArray())->toBe([
            'prompt_tokens' => 33,
            'completion_tokens' => 1817,
            'cache_write_input_tokens' => null,
            'cache_read_input_tokens' => 4608,
            'thought_tokens' => 1200,
        ]);
    });

    it('reads usage that trails a tool call finish and sums each step once', function (): void {
        $toolFinish = groqGatingToolChunk('tool_calls');
        $toolFinish['x_groq'] = ['usage' => ['prompt_tokens' => 300, 'completion_tokens' => 40, 'total_tokens' => 340]];
        $toolFinish['usage'] = $toolFinish['x_groq']['usage'];

        Http::fake([
            '*' => Http::sequence()
                ->push(groqGatingSse(
                    $toolFinish,
                    ['id' => 'chatcmpl-gating', 'model' => 'llama-3.3-70b-versatile', 'choices' => [], 'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 40, 'total_tokens' => 340]],
                ), 200, ['Content-Type' => 'text/event-stream'])
                ->push(groqGatingSse(groqGatingFinishChunk('stop')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = groqGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
                'prompt_tokens' => 310,
                'completion_tokens' => 45,
                'cache_write_input_tokens' => null,
                'cache_read_input_tokens' => null,
                'thought_tokens' => null,
            ])
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map(fn (StepFinishEvent $event): array => [$event->usage->promptTokens, $event->usage->completionTokens])->values()->all())
            ->toBe([[300, 40], [10, 5]]);
    });

    it('reports every step when max steps ends the stream on a tool call', function (): void {
        $toolFinish = groqGatingToolChunk('tool_calls');
        $toolFinish['x_groq'] = ['usage' => ['prompt_tokens' => 300, 'completion_tokens' => 40, 'total_tokens' => 340]];

        $secondToolFinish = groqGatingToolChunk('tool_calls');
        $secondToolFinish['usage'] = ['prompt_tokens' => 360, 'completion_tokens' => 30, 'total_tokens' => 390, 'prompt_tokens_details' => ['cached_tokens' => 256]];

        Http::fake([
            '*' => Http::sequence()
                ->push(groqGatingSse($toolFinish), 200, ['Content-Type' => 'text/event-stream'])
                ->push(groqGatingSse($secondToolFinish), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $events = collect(iterator_to_array(Prism::text()
            ->using('groq', 'llama-3.3-70b-versatile')
            ->withTools([Tool::as('get_weather')->for('Get the weather')->withStringParameter('city', 'The city')->using(fn (string $city): string => "Sunny in {$city}")])
            ->withMaxSteps(2)
            ->withPrompt('What is the weather in Detroit?')
            ->asStream(), false));

        expect($events->whereInstanceOf(StreamEndEvent::class))->toHaveCount(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
                'prompt_tokens' => 404,
                'completion_tokens' => 70,
                'cache_write_input_tokens' => null,
                'cache_read_input_tokens' => 256,
                'thought_tokens' => null,
            ]);
    });
});

describe('content blocks', function (): void {
    it('completes every text block it starts exactly once', function (array $responses, array $expected): void {
        $sequence = Http::sequence();

        foreach ($responses as $chunks) {
            $sequence->push(groqGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']);
        }

        Http::fake(['*' => $sequence])->preventStrayRequests();

        $calls = 0;

        expect(groqGatingStream($calls)->map(fn (StreamEvent $event): string => class_basename($event))->values()->all())->toBe($expected);
    })->with([
        'text before tool calls' => [
            [
                [groqGatingDeltaChunk(['content' => 'Let me check.']), groqGatingToolChunk(), groqGatingFinishChunk('tool_calls')],
                [groqGatingFinishChunk('stop')],
            ],
            ['StreamStartEvent', 'StepStartEvent', 'TextStartEvent', 'TextDeltaEvent', 'TextCompleteEvent', 'ToolCallEvent', 'ToolResultEvent', 'StepFinishEvent', 'StepStartEvent', 'StepFinishEvent', 'StreamEndEvent'],
        ],
        'text before a tool call cut off by length' => [
            [[groqGatingDeltaChunk(['content' => 'Let me check.']), groqGatingToolChunk('length')]],
            ['StreamStartEvent', 'StepStartEvent', 'TextStartEvent', 'TextDeltaEvent', 'TextCompleteEvent', 'StepFinishEvent', 'StreamEndEvent'],
        ],
        'text when the stream closes early' => [
            [[groqGatingDeltaChunk(['content' => 'Sunny.'])]],
            ['StreamStartEvent', 'StepStartEvent', 'TextStartEvent', 'TextDeltaEvent', 'TextCompleteEvent', 'StepFinishEvent', 'StreamEndEvent'],
        ],
    ]);
});

describe('in-stream errors', function (): void {
    it('ends the step and the stream with Error after an unrecoverable error chunk', function (array $chunks): void {
        Http::fake([
            '*' => Http::response(groqGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = groqGatingStream($calls);

        expect($calls)->toBe(0)
            ->and($events->map(fn (StreamEvent $event): string => class_basename($event))->values()->all())->toBe([
                'StreamStartEvent', 'StepStartEvent', 'TextStartEvent', 'TextDeltaEvent', 'ErrorEvent', 'TextCompleteEvent', 'StepFinishEvent', 'StreamEndEvent',
            ])
            ->and($events->whereInstanceOf(ErrorEvent::class)->sole()->recoverable)->toBeFalse()
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::Error)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Error)
            ->and([$events->last()->usage->promptTokens, $events->last()->usage->completionTokens])->toBe([10, 5]);
    })->with([
        'error then end of stream' => [[
            groqGatingDeltaChunk(['content' => 'Sunny']),
            ['error' => ['type' => 'server_error', 'message' => 'generation failed'], 'x_groq' => ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]]],
        ]],
        'error then a finish reason' => [[
            groqGatingDeltaChunk(['content' => 'Sunny']),
            ['error' => ['type' => 'server_error', 'message' => 'generation failed']],
            groqGatingDeltaChunk(['content' => ' and warm.']),
            groqGatingFinishChunk('tool_calls'),
        ]],
    ]);
});
