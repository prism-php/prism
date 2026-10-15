<?php

declare(strict_types=1);

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
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openrouter.api_key', env('OPENROUTER_API_KEY'));
});

it('can stream text with a prompt', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-a-prompt');

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
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

    expect($events)->not->toBeEmpty();

    // Check first event is StreamStartEvent
    expect($events[0])->toBeInstanceOf(StreamStartEvent::class);
    expect($events[0]->model)->toBe('openai/gpt-4-turbo');
    expect($events[0]->provider)->toBe('openrouter');

    // Check we have TextStartEvent
    $textStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof TextStartEvent);
    expect($textStartEvents)->toHaveCount(1);

    // Check we have TextDeltaEvents
    $textDeltaEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof TextDeltaEvent);
    expect($textDeltaEvents)->not->toBeEmpty();

    // Check we have TextCompleteEvent
    $textCompleteEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof TextCompleteEvent);
    expect($textCompleteEvents)->toHaveCount(1);

    // Check last event is StreamEndEvent
    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
    expect($lastEvent->usage)->not->toBeNull();
    expect($lastEvent->usage->promptTokens)->toBe(7);
    expect($lastEvent->usage->completionTokens)->toBe(35);

    // Verify full text can be reconstructed
    expect($text)->toBe("Hello! I'm an AI assistant powered by OpenRouter. How can I help you today?");
});

it('forwards advanced provider options in streaming mode', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-a-prompt');

    $stream = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withPrompt('Stream a short update.')
        // Confirm streaming payload honors OpenRouter streaming guidance https://openrouter.ai/docs/api-reference/streaming
        ->withProviderOptions([
            'stop' => ['END_STREAM'],
            'seed' => 11,
            'top_k' => 16,
            'frequency_penalty' => 0.4,
            'presence_penalty' => 0.15,
            'repetition_penalty' => 1.05,
            'min_p' => 0.05,
            'top_a' => 0.2,
            'logit_bias' => ['101' => -2],
            'logprobs' => false,
            'top_logprobs' => 2,
            'prediction' => [
                'type' => 'content',
                'content' => 'Update:',
            ],
            'transforms' => ['markdown'],
            'models' => ['openai/gpt-4-turbo', 'google/gemini-pro'],
            'route' => 'fallback',
            'provider' => ['require_parameters' => true],
            'user' => 'customer-stream-11',
            'parallel_tool_calls' => true,
            'verbosity' => 'low',
        ])
        ->asStream();

    // Prime the generator so the HTTP request is executed.
    foreach ($stream as $_event) {
        break;
    }

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $payload['stream'] === true
            && $payload['stop'] === ['END_STREAM']
            && $payload['seed'] === 11
            && $payload['top_k'] === 16
            && $payload['frequency_penalty'] === 0.4
            && $payload['presence_penalty'] === 0.15
            && $payload['repetition_penalty'] === 1.05
            && $payload['min_p'] === 0.05
            && $payload['top_a'] === 0.2
            && $payload['logit_bias'] === ['101' => -2]
            && $payload['logprobs'] === false
            && $payload['top_logprobs'] === 2
            && $payload['prediction'] === [
                'type' => 'content',
                'content' => 'Update:',
            ]
            && $payload['transforms'] === ['markdown']
            && $payload['models'] === ['openai/gpt-4-turbo', 'google/gemini-pro']
            && $payload['route'] === 'fallback'
            && $payload['provider'] === ['require_parameters' => true]
            && $payload['user'] === 'customer-stream-11'
            && $payload['parallel_tool_calls'] === true
            && $payload['verbosity'] === 'low'
            && $payload['stream_options'] === ['include_usage' => true];
    });
});

it('can stream text with tool calls', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-tools');

    $weatherTool = Tool::as('weather')
        ->for('Get weather for a city')
        ->withStringParameter('city', 'The city name')
        ->using(fn (string $city): string => "The weather in {$city} is 75°F and sunny");

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withTools([$weatherTool])
        ->withMaxSteps(3)
        ->withPrompt('What is the weather in San Francisco?')
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
            expect($event->toolCall->name)->toBe('weather');
            expect($event->toolCall->arguments())->toBe(['city' => 'San Francisco']);
        }

        if ($event instanceof ToolResultEvent) {
            $toolResultEvents[] = $event;
            expect($event->toolResult->result)->toBe('The weather in San Francisco is 75°F and sunny');
        }
    }

    expect($events)->not->toBeEmpty();
    expect($toolCallEvents)->toHaveCount(1);
    expect($toolResultEvents)->toHaveCount(1);

    // Verify text from first response
    expect($text)->toContain("I'll help you get the weather for you.");

    // Verify only one StreamStartEvent and one StreamEndEvent
    $streamStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEndEvent);
    expect($streamStartEvents)->toHaveCount(1);
    expect($streamEndEvents)->toHaveCount(1);

    $lastStreamEnd = array_values($streamEndEvents)[array_key_last(array_values($streamEndEvents))];
    expect($lastStreamEnd->usage)->not->toBeNull();
    expect($lastStreamEnd->usage->promptTokens)->toBe(125);
    expect($lastStreamEnd->usage->completionTokens)->toBe(50);

    $stepUsage = collect($events)->whereInstanceOf(StepFinishEvent::class)->map(fn (StepFinishEvent $event): array => [$event->usage->promptTokens, $event->usage->completionTokens])->values()->all();
    expect($stepUsage)->toBe([[50, 25], [75, 25]]);
});

it('can stream text with empty parameters tool calls when using gpt-5', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-empty-parameters-tools-when-using-gpt-5');

    $currentTime = '08:00:00';
    $timeTool = Tool::as('time')
        ->for('Get the current time')
        ->using(fn (): string => $currentTime);

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-5')
        ->withTools([$timeTool])
        ->withMaxSteps(3)
        ->withPrompt('Please tell me the current time, use the `time` tool')
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
            expect($event->toolCall->name)->toBe('time');
            expect($event->toolCall->arguments())->toBe([]);
        }

        if ($event instanceof ToolResultEvent) {
            $toolResultEvents[] = $event;
            expect($event->toolResult->result)->toContain($currentTime);
        }
    }

    expect($events)->not->toBeEmpty();
    expect($toolCallEvents)->toHaveCount(1);
    expect($toolResultEvents)->toHaveCount(1);
    expect($text)->toContain('The current time is '.$currentTime);

    $streamEndEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEndEvent);
    expect($streamEndEvents)->toHaveCount(1);

    expect(collect($events)->whereInstanceOf(StepFinishEvent::class)->map(fn (StepFinishEvent $event): array => $event->usage->toArray())->values()->all())->toBe([
        ['prompt_tokens' => 50, 'completion_tokens' => 143, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 0, 'thought_tokens' => 128],
        ['prompt_tokens' => 75, 'completion_tokens' => 15, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 0, 'thought_tokens' => 0],
    ]);
    expect(array_values($streamEndEvents)[0]->usage->toArray())->toBe([
        'prompt_tokens' => 125,
        'completion_tokens' => 158,
        'cache_write_input_tokens' => null,
        'cache_read_input_tokens' => 0,
        'thought_tokens' => 128,
    ]);
});

it('can handle reasoning/thinking tokens in streaming', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-reasoning');

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/o1-preview')
        ->withPrompt('Solve this math problem: 2 + 2 = ?')
        ->asStream();

    $events = [];
    $thinkingEvents = [];
    $text = '';

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof ThinkingEvent) {
            $thinkingEvents[] = $event;
        }

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($events)->not->toBeEmpty();

    // Check for ThinkingStartEvent
    $thinkingStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof ThinkingStartEvent);
    expect($thinkingStartEvents)->toHaveCount(1);

    // Check for ThinkingEvent
    expect($thinkingEvents)->toHaveCount(1);
    expect($thinkingEvents[0]->delta)->toContain('math problem');

    // Check text was assembled
    expect($text)->toBe('The answer to 2 + 2 is 4.');

    // Check for usage with reasoning tokens
    $streamEndEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEndEvent);
    expect($streamEndEvents)->toHaveCount(1);

    $streamEndEvent = array_values($streamEndEvents)[0];
    expect($streamEndEvent->usage)->not->toBeNull();
    expect($streamEndEvent->usage->thoughtTokens)->toBe(12);

    expect($streamEndEvent->additionalContent)->toHaveKey('reasoning');
    expect($streamEndEvent->additionalContent['reasoning'])->toContain('math problem');
});

it('captures reasoning_details from streaming responses', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-reasoning-details');

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-5')
        ->withPrompt('What is 2 + 2?')
        ->asStream();

    $events = [];
    $text = '';

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($text)->toBe('The answer is 4.');

    $thinkingStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof ThinkingStartEvent);
    expect($thinkingStartEvents)->toBeEmpty();

    $streamEndEvent = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEndEvent))[0];
    expect($streamEndEvent->additionalContent)->toHaveKey('reasoning_details');
    expect($streamEndEvent->additionalContent['reasoning_details'])->toHaveCount(1);
    expect($streamEndEvent->additionalContent['reasoning_details'][0]['type'])->toBe('reasoning.encrypted');
    expect($streamEndEvent->additionalContent['reasoning_details'][0]['id'])->toBe('rs_045e88aab38b');

    expect($streamEndEvent->usage->thoughtTokens)->toBe(15);
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-a-prompt');

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
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
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-tools');

    $weatherTool = Tool::as('weather')
        ->for('Get weather for a city')
        ->withStringParameter('city', 'The city name')
        ->using(fn (string $city): string => "The weather in {$city} is 75°F and sunny");

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withTools([$weatherTool])
        ->withMaxSteps(3)
        ->withPrompt('What is the weather in San Francisco?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    // Extract step events
    $stepStartEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent));
    $stepFinishEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent));

    // Should have 2 steps: tool call step + final response step
    expect($stepStartEvents)->toHaveCount(2);
    expect($stepFinishEvents)->toHaveCount(2);

    // Verify step start/finish pairs are balanced
    expect(count($stepStartEvents))->toBe(count($stepFinishEvents));

    // Verify event ordering using indices
    $getIndices = fn (string $class): array => array_keys(array_filter($events, fn (StreamEvent $e): bool => $e instanceof $class));

    $streamStartIdx = $getIndices(StreamStartEvent::class)[0];
    $stepStartIndices = $getIndices(StepStartEvent::class);
    $stepFinishIndices = $getIndices(StepFinishEvent::class);
    $toolCallIndices = $getIndices(ToolCallEvent::class);
    $toolResultIndices = $getIndices(ToolResultEvent::class);
    $streamEndIdx = $getIndices(StreamEndEvent::class)[0];

    // Verify overall structure: StreamStart -> Steps -> StreamEnd
    expect($streamStartIdx)->toBeLessThan($stepStartIndices[0]);
    expect($stepFinishIndices[count($stepFinishIndices) - 1])->toBeLessThan($streamEndIdx);

    // Verify each step has proper start/finish ordering
    foreach ($stepStartIndices as $i => $startIdx) {
        expect($startIdx)->toBeLessThan($stepFinishIndices[$i], "Step $i: start should come before finish");
    }

    // Verify tool call happens within first step (before first step finish)
    expect($toolCallIndices[0])->toBeGreaterThan($stepStartIndices[0]);
    expect($toolCallIndices[0])->toBeLessThan($stepFinishIndices[0]);

    // Verify tool result happens after tool call but before second step starts
    expect($toolResultIndices[0])->toBeGreaterThan($toolCallIndices[0]);
    expect($toolResultIndices[0])->toBeLessThan($stepStartIndices[1]);
});

it('sends StreamEndEvent using tools with streaming and max steps = 1', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-tools');

    $weatherTool = Tool::as('weather')
        ->for('Get weather for a city')
        ->withStringParameter('city', 'The city name')
        ->using(fn (string $city): string => "The weather in {$city} is 75°F and sunny");

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withTools([$weatherTool])
        ->withMaxSteps(1)
        ->withPrompt('What is the weather in San Francisco?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect($events)->not->toBeEmpty();

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
});

function openRouterGatingSse(array ...$chunks): string
{
    return collect($chunks)
        ->map(fn (array $chunk): string => 'data: '.json_encode($chunk)."\n\n")
        ->implode('')."data: [DONE]\n\n";
}

/**
 * @return array<string, mixed>
 */
function openRouterGatingToolChunk(?string $finishReason = null): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'openai/gpt-4o',
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
function openRouterGatingFinishChunk(string $finishReason): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'openai/gpt-4o',
        'choices' => [['index' => 0, 'delta' => ['content' => ''], 'finish_reason' => $finishReason]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ];
}

/**
 * @return Collection<int, StreamEvent>
 */
function openRouterGatingStream(int &$calls): Collection
{
    return collect(iterator_to_array(Prism::text()
        ->using('openrouter', 'openai/gpt-4o')
        ->withTools([
            Tool::as('get_weather')
                ->for('Get the weather for a city')
                ->withStringParameter('city', 'The city')
                ->using(function (string $city) use (&$calls): string {
                    $calls++;

                    return "Sunny in {$city}";
                }),
        ])
        ->withMaxSteps(3)
        ->withPrompt('What is the weather in Detroit?')
        ->asStream(), false));
}

describe('tool execution gating', function (): void {
    it('never runs buffered tool calls when the step does not finish with tool calls', function (array $chunks, FinishReason $finishReason): void {
        Http::fake([
            '*' => Http::response(openRouterGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = openRouterGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(0)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe($finishReason)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe($finishReason);

        Http::assertSentCount(1);
    })->with([
        'length after the tool call' => [[openRouterGatingToolChunk(), openRouterGatingFinishChunk('length')], FinishReason::Length],
        'length on the tool call chunk' => [[openRouterGatingToolChunk('length')], FinishReason::Length],
        'content filter after the tool call' => [[openRouterGatingToolChunk(), openRouterGatingFinishChunk('content_filter')], FinishReason::ContentFilter],
        'stream ends without a finish reason' => [[openRouterGatingToolChunk()], FinishReason::Stop],
    ]);

    it('runs tool calls once when the step finishes with tool calls', function (array $chunks): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(openRouterGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream'])
                ->push(openRouterGatingSse(openRouterGatingFinishChunk('stop')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = openRouterGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop]);

        Http::assertSentCount(2);
    })->with([
        'tool_calls in a separate chunk' => [[openRouterGatingToolChunk(), openRouterGatingFinishChunk('tool_calls')]],
        'tool_calls on the tool call chunk' => [[openRouterGatingToolChunk('tool_calls')]],
    ]);
});

/**
 * @param  array<string, mixed>  $delta
 * @return array<string, mixed>
 */
function openRouterGatingDeltaChunk(array $delta, ?string $finishReason = null): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'openai/gpt-4o',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
    ];
}

describe('tool execution gating with mixed chunks', function (): void {
    it('runs tool calls once when the tool-call finish arrives on a chunk with other deltas', function (array $delta, string $streamedDelta): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(openRouterGatingSse(openRouterGatingToolChunk(), openRouterGatingDeltaChunk($delta, 'tool_calls')), 200, ['Content-Type' => 'text/event-stream'])
                ->push(openRouterGatingSse(openRouterGatingFinishChunk('stop')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = openRouterGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop])
            ->and($events->whereInstanceOf([TextDeltaEvent::class, ThinkingEvent::class])->map->delta->implode(''))->toBe($streamedDelta);

        Http::assertSentCount(2);
    })->with([
        'content' => [['content' => 'Checking the weather.'], 'Checking the weather.'],
        'reasoning' => [['reasoning' => 'Thinking it over.'], 'Thinking it over.'],
    ]);

    it('never runs tool calls when a non-tool finish arrives on a chunk with other deltas', function (array $delta, string $streamedDelta): void {
        Http::fake([
            '*' => Http::response(openRouterGatingSse(openRouterGatingToolChunk(), openRouterGatingDeltaChunk($delta, 'length')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = openRouterGatingStream($calls);

        expect($calls)->toBe(0)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Length)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::Length)
            ->and($events->whereInstanceOf([TextDeltaEvent::class, ThinkingEvent::class])->map->delta->implode(''))->toBe($streamedDelta);

        Http::assertSentCount(1);
    })->with([
        'content' => [['content' => 'Checking the weather.'], 'Checking the weather.'],
        'reasoning' => [['reasoning' => 'Thinking it over.'], 'Thinking it over.'],
    ]);

    it('ends with Stop when the step after a tool call has no finish reason', function (): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(openRouterGatingSse(openRouterGatingToolChunk(), openRouterGatingFinishChunk('tool_calls')), 200, ['Content-Type' => 'text/event-stream'])
                ->push(openRouterGatingSse(openRouterGatingDeltaChunk(['content' => 'Sunny in Detroit.'])), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = openRouterGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop]);

        Http::assertSentCount(2);
    });
});

/**
 * @param  array<string, mixed>  $usage
 * @return array<string, mixed>
 */
function openRouterUsageFrame(string $finishReason, array $usage): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'openai/gpt-4o',
        'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => $finishReason, 'native_finish_reason' => $finishReason]],
        'usage' => $usage,
    ];
}

describe('usage snapshots', function (): void {
    it('reads the trailing usage frame of each step without finishing the step twice', function (): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(openRouterGatingSse(
                    openRouterGatingToolChunk('tool_calls'),
                    openRouterUsageFrame('tool_calls', [
                        'prompt_tokens' => 1200,
                        'completion_tokens' => 80,
                        'total_tokens' => 1280,
                        'prompt_tokens_details' => ['cached_tokens' => 1000, 'cache_write_tokens' => 100],
                        'completion_tokens_details' => ['reasoning_tokens' => 60],
                    ]),
                ), 200, ['Content-Type' => 'text/event-stream'])
                ->push(openRouterGatingSse(
                    openRouterGatingDeltaChunk(['content' => 'Sunny in Detroit.']),
                    openRouterGatingDeltaChunk([], 'stop'),
                    openRouterUsageFrame('stop', [
                        'prompt_tokens' => 1300,
                        'completion_tokens' => 20,
                        'total_tokens' => 1320,
                        'prompt_tokens_details' => ['cached_tokens' => 1200],
                        'completion_tokens_details' => ['reasoning_tokens' => 0],
                    ]),
                ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = openRouterGatingStream($calls);
        $stepFinishes = $events->whereInstanceOf(StepFinishEvent::class)->values();

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(ToolCallEvent::class))->toHaveCount(1)
            ->and($events->whereInstanceOf(TextCompleteEvent::class))->toHaveCount(1)
            ->and($stepFinishes->map->finishReason->all())->toBe([FinishReason::ToolCalls, FinishReason::Stop])
            ->and($stepFinishes->map(fn (StepFinishEvent $event): array => $event->usage->toArray())->all())->toBe([
                ['prompt_tokens' => 100, 'completion_tokens' => 80, 'cache_write_input_tokens' => 100, 'cache_read_input_tokens' => 1000, 'thought_tokens' => 60],
                ['prompt_tokens' => 100, 'completion_tokens' => 20, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 1200, 'thought_tokens' => 0],
            ])
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
                'prompt_tokens' => 200,
                'completion_tokens' => 100,
                'cache_write_input_tokens' => 100,
                'cache_read_input_tokens' => 2200,
                'thought_tokens' => 60,
            ]);

        Http::assertSentCount(2);
    });

    it('counts the usage of a tool step once when max steps ends the stream on it', function (): void {
        Http::fake([
            '*' => Http::response(openRouterGatingSse(
                openRouterGatingToolChunk('tool_calls'),
                openRouterUsageFrame('tool_calls', [
                    'prompt_tokens' => 300,
                    'completion_tokens' => 40,
                    'total_tokens' => 340,
                    'prompt_tokens_details' => ['cached_tokens' => 256],
                    'completion_tokens_details' => ['reasoning_tokens' => 30],
                ]),
            ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $events = collect(iterator_to_array(Prism::text()
            ->using('openrouter', 'openai/gpt-4o')
            ->withTools([Tool::as('get_weather')->for('Get the weather')->withStringParameter('city', 'The city')->using(fn (string $city): string => "Sunny in {$city}")])
            ->withMaxSteps(1)
            ->withPrompt('What is the weather in Detroit?')
            ->asStream(), false));

        $expected = ['prompt_tokens' => 44, 'completion_tokens' => 40, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 256, 'thought_tokens' => 30];

        expect($events->whereInstanceOf(StepFinishEvent::class)->sole()->usage->toArray())->toBe($expected)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe($expected);

        Http::assertSentCount(1);
    });

    it('keeps the latest usage snapshot of a step instead of adding them up', function (): void {
        Http::fake([
            '*' => Http::response(openRouterGatingSse(
                [...openRouterGatingDeltaChunk(['content' => 'Hi']), 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 1, 'total_tokens' => 11]],
                [...openRouterGatingDeltaChunk([], 'stop'), 'usage' => null],
                openRouterUsageFrame('stop', ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12]),
            ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = openRouterGatingStream($calls);

        expect($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
            'prompt_tokens' => 10,
            'completion_tokens' => 2,
            'cache_write_input_tokens' => null,
            'cache_read_input_tokens' => null,
            'thought_tokens' => null,
        ]);
    });

    it('floors prompt tokens at zero when a response-cache replay reports more cached than prompt tokens', function (): void {
        Http::fake([
            '*' => Http::response(openRouterGatingSse(
                openRouterGatingDeltaChunk(['content' => 'Cached answer.'], 'stop'),
                openRouterUsageFrame('stop', [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                    'prompt_tokens_details' => ['cached_tokens' => 512],
                ]),
            ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $usage = openRouterGatingStream($calls)->whereInstanceOf(StreamEndEvent::class)->sole()->usage;

        expect($usage->promptTokens)->toBe(0)
            ->and($usage->cacheReadInputTokens)->toBe(512)
            ->and($usage->completionTokens)->toBe(0);
    });
});
