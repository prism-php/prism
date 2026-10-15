<?php

declare(strict_types=1);

namespace Tests\Providers\Ollama;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
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
    $events = [];
    $model = null;
    $hasStreamEndEvent = false;

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof StreamStartEvent) {
            $model = $event->model;
        }

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }

        if ($event instanceof StreamEndEvent) {
            $hasStreamEndEvent = true;
            expect($event->finishReason)->toBe(FinishReason::Stop);
        }
    }

    expect($events)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();
    expect($model)->toBe('granite3-dense:8b');
    expect($hasStreamEndEvent)->toBeTrue();
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
            ->using(fn (string $query): string => 'The tigers game is at 3pm today'),
    ];

    $response = Prism::text()
        ->using('ollama', 'qwen3:14b')
        ->withTools($tools)
        ->withMaxSteps(6)
        ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat?')
        ->asStream();

    $text = '';
    $events = [];
    $toolCallEvents = [];
    $toolResultEvents = [];
    $finishReasonFound = false;

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }

        if ($event instanceof ToolCallEvent) {
            $toolCallEvents[] = $event;
        }

        if ($event instanceof ToolResultEvent) {
            $toolResultEvents[] = $event;
        }

        if ($event instanceof StreamEndEvent) {
            $finishReasonFound = true;
            expect($event->finishReason)->toBe(FinishReason::Stop);
        }
    }

    expect($events)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();

    expect($toolCallEvents)->toHaveCount(2);
    expect($toolResultEvents)->toHaveCount(2);

    // For the basic tools test, validate completion state
    expect($finishReasonFound)->toBeTrue();

    // Verify only one StreamStartEvent and one StreamEndEvent
    $streamStartEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEndEvent);
    expect($streamStartEvents)->toHaveCount(1);
    expect($streamEndEvents)->toHaveCount(1);

    // These recordings predate prompt_eval_cached_count, so the cache bucket stays null
    expect(array_values($streamEndEvents)[0]->usage->toArray())->toBe([
        'prompt_tokens' => 787,
        'completion_tokens' => 646,
        'cache_write_input_tokens' => null,
        'cache_read_input_tokens' => null,
        'thought_tokens' => null,
    ]);

    $stepUsage = collect($events)
        ->whereInstanceOf(StepFinishEvent::class)
        ->map(fn (StepFinishEvent $event): array => [$event->usage->promptTokens, $event->usage->completionTokens])
        ->values()
        ->all();

    expect($stepUsage)->toBe([[218, 306], [263, 152], [306, 188]]);
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

it('includes think parameter when thinking is enabled for streaming', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-with-thinking-enabled');

    $response = Prism::text()
        ->using('ollama', 'gpt-oss')
        ->withPrompt('Test prompt')
        ->withProviderOptions(['thinking' => true])
        ->asStream();

    // Consume the stream to trigger the HTTP request
    foreach ($response as $chunk) {
        break;
    }

    Http::assertSent(function (Request $request): true {
        $body = $request->data();
        expect($body)->toHaveKey('think');
        expect($body['think'])->toBe(true);

        return true;
    });
});

it('does not include think parameter when not provided for streaming', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-without-thinking');

    $response = Prism::text()
        ->using('ollama', 'gpt-oss')
        ->withPrompt('Test prompt')
        ->asStream();

    // Consume the stream to trigger the HTTP request
    foreach ($response as $chunk) {
        break;
    }

    Http::assertSent(function (Request $request): true {
        $body = $request->data();
        expect($body)->not->toHaveKey('think');

        return true;
    });
});

it('includes keep_alive parameter when provided for streaming', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-without-thinking');

    $response = Prism::text()
        ->using('ollama', 'gpt-oss')
        ->withPrompt('Test prompt')
        ->withProviderOptions(['keep_alive' => '5m'])
        ->asStream();

    // Consume the stream to trigger the HTTP request
    foreach ($response as $chunk) {
        break;
    }

    Http::assertSent(function (Request $request): true {
        $body = $request->data();
        expect($body)->toHaveKey('keep_alive');
        expect($body['keep_alive'])->toBe('5m');

        return true;
    });
});

it('supports numeric keep_alive values for streaming', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-without-thinking');

    $response = Prism::text()
        ->using('ollama', 'gpt-oss')
        ->withPrompt('Test prompt')
        ->withProviderOptions(['keep_alive' => -1])
        ->asStream();

    // Consume the stream to trigger the HTTP request
    foreach ($response as $chunk) {
        break;
    }

    Http::assertSent(function (Request $request): true {
        $body = $request->data();
        expect($body)->toHaveKey('keep_alive');
        expect($body['keep_alive'])->toBe(-1);

        return true;
    });
});

it('does not include keep_alive parameter when not provided for streaming', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-without-thinking');

    $response = Prism::text()
        ->using('ollama', 'gpt-oss')
        ->withPrompt('Test prompt')
        ->asStream();

    // Consume the stream to trigger the HTTP request
    foreach ($response as $chunk) {
        break;
    }

    Http::assertSent(function (Request $request): true {
        $body = $request->data();
        expect($body)->not->toHaveKey('keep_alive');

        return true;
    });
});

it('emits thinking chunks when provider sends thinking field', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-with-thinking');

    $response = Prism::text()
        ->using('ollama', 'gpt-oss:20b')
        ->withPrompt('Should I bring a jacket?')
        ->asStream();

    $sawThinking = false;
    $sawText = false;
    $thinkingTexts = [];
    $finalText = '';
    $lastFinishReason = null;

    foreach ($response as $event) {
        if ($event instanceof ThinkingEvent) {
            $sawThinking = true;
            $thinkingTexts[] = $event->delta;
        }

        if ($event instanceof TextDeltaEvent) {
            $sawText = true;
            $finalText .= $event->delta;
        }

        if ($event instanceof StreamEndEvent) {
            $lastFinishReason = $event->finishReason;
        }
    }

    expect($sawThinking)->toBeTrue();
    expect($sawText)->toBeTrue();
    expect($thinkingTexts)->not->toBeEmpty();
    expect($finalText)->toContain('Here is the answer:');
    expect($lastFinishReason)->toBe(FinishReason::Stop);
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-basic-text');

    $response = Prism::text()
        ->using('ollama', 'granite3-dense:8b')
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
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-with-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm today'),
    ];

    $response = Prism::text()
        ->using('ollama', 'qwen3:14b')
        ->withTools($tools)
        ->withMaxSteps(6)
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
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-with-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm today'),
    ];

    $response = Prism::text()
        ->using('ollama', 'qwen3:14b')
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
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
});

function ollamaGatingStreamBody(array ...$chunks): string
{
    return collect($chunks)
        ->map(fn (array $chunk): string => json_encode($chunk)."\n")
        ->implode('');
}

/**
 * @return array<string, mixed>
 */
function ollamaGatingToolChunk(?string $doneReason = null): array
{
    return array_filter([
        'model' => 'qwen3:14b',
        'created_at' => '2025-06-09T17:58:55.337823Z',
        'message' => [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [['function' => ['name' => 'get_weather', 'arguments' => ['city' => 'Detroit']]]],
        ],
        'done_reason' => $doneReason,
        'done' => $doneReason !== null,
    ], fn (mixed $value): bool => $value !== null);
}

/**
 * @return array<string, mixed>
 */
function ollamaGatingDoneChunk(?string $doneReason): array
{
    return array_filter([
        'model' => 'qwen3:14b',
        'created_at' => '2025-06-09T17:58:57.192416Z',
        'message' => ['role' => 'assistant', 'content' => ''],
        'done_reason' => $doneReason,
        'done' => true,
        'prompt_eval_count' => 10,
        'eval_count' => 5,
    ], fn (mixed $value): bool => $value !== null);
}

/**
 * @return array<string, mixed>
 */
function ollamaGatingThinkingDoneChunk(string $doneReason): array
{
    return [
        ...ollamaGatingDoneChunk($doneReason),
        'message' => ['role' => 'assistant', 'content' => '', 'thinking' => 'Done thinking.'],
    ];
}

/**
 * @return Collection<int, StreamEvent>
 */
function ollamaGatingStream(int &$calls): Collection
{
    return collect(iterator_to_array(Prism::text()
        ->using('ollama', 'qwen3:14b')
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
    it('never runs tool calls when the turn does not complete normally', function (array $chunks, FinishReason $finishReason): void {
        Http::fake([
            '*' => Http::response(ollamaGatingStreamBody(...$chunks), 200),
        ])->preventStrayRequests();

        $calls = 0;
        $events = ollamaGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(0)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe($finishReason)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe($finishReason);

        Http::assertSentCount(1);
    })->with([
        'length after the tool call' => [[ollamaGatingToolChunk(), ollamaGatingDoneChunk('length')], FinishReason::Length],
        'length on the tool call chunk' => [[ollamaGatingToolChunk('length')], FinishReason::Length],
        'done without a reason' => [[ollamaGatingToolChunk(), ollamaGatingDoneChunk(null)], FinishReason::Unknown],
        'stream ends before done' => [[ollamaGatingToolChunk()], FinishReason::Stop],
        'length on a thinking chunk' => [[ollamaGatingToolChunk(), ollamaGatingThinkingDoneChunk('length')], FinishReason::Length],
    ]);

    it('runs tool calls once when the turn completes normally', function (array $chunks): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(ollamaGatingStreamBody(...$chunks), 200)
                ->push(ollamaGatingStreamBody(ollamaGatingDoneChunk('stop')), 200),
        ])->preventStrayRequests();

        $calls = 0;
        $events = ollamaGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop]);

        Http::assertSentCount(2);
    })->with([
        'stop after the tool call' => [[ollamaGatingToolChunk(), ollamaGatingDoneChunk('stop')]],
        'stop on the tool call chunk' => [[ollamaGatingToolChunk('stop')]],
        'stop on a thinking chunk' => [[ollamaGatingToolChunk(), ollamaGatingThinkingDoneChunk('stop')]],
    ]);
});

describe('usage', function (): void {
    it('takes prompt_eval_cached_count out of the prompt and sums each step once', function (): void {
        $toolDone = [...ollamaGatingToolChunk('stop'), 'prompt_eval_count' => 120, 'prompt_eval_cached_count' => 0, 'eval_count' => 30];
        $finalDone = [...ollamaGatingDoneChunk('stop'), 'prompt_eval_count' => 160, 'prompt_eval_cached_count' => 128, 'eval_count' => 12];

        Http::fake([
            '*' => Http::sequence()
                ->push(ollamaGatingStreamBody($toolDone))
                ->push(ollamaGatingStreamBody($finalDone)),
        ])->preventStrayRequests();

        $calls = 0;
        $events = ollamaGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
                'prompt_tokens' => 152,
                'completion_tokens' => 42,
                'cache_write_input_tokens' => null,
                'cache_read_input_tokens' => 128,
                'thought_tokens' => null,
            ])
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map(fn (StepFinishEvent $event): array => [$event->usage->promptTokens, $event->usage->cacheReadInputTokens, $event->usage->completionTokens])->values()->all())
            ->toBe([[120, 0, 30], [32, 128, 12]]);
    });

    it('reports every step when max steps ends the stream on a tool call', function (): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(ollamaGatingStreamBody([...ollamaGatingToolChunk('stop'), 'prompt_eval_count' => 120, 'eval_count' => 30]))
                ->push(ollamaGatingStreamBody([...ollamaGatingToolChunk('stop'), 'prompt_eval_count' => 170, 'prompt_eval_cached_count' => 64, 'eval_count' => 25])),
        ])->preventStrayRequests();

        $events = collect(iterator_to_array(Prism::text()
            ->using('ollama', 'qwen3:14b')
            ->withTools([Tool::as('get_weather')->for('Get the weather')->withStringParameter('city', 'The city')->using(fn (string $city): string => "Sunny in {$city}")])
            ->withMaxSteps(2)
            ->withPrompt('What is the weather in Detroit?')
            ->asStream(), false));

        expect($events->whereInstanceOf(StreamEndEvent::class))->toHaveCount(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
                'prompt_tokens' => 226,
                'completion_tokens' => 55,
                'cache_write_input_tokens' => null,
                'cache_read_input_tokens' => 64,
                'thought_tokens' => null,
            ]);
    });
});
