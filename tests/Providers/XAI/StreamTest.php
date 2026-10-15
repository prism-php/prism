<?php

declare(strict_types=1);

namespace Tests\Providers\XAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
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
    config()->set('prism.providers.xai.api_key', env('XAI_API_KEY', 'fake-key'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('xai', 'grok-4')
        ->withPrompt('Who are you?')
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

    expect($events)->not
        ->toBeEmpty()
        ->and($text)->not
        ->toBeEmpty()
        ->and($model)->toBe('grok-4');

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.x.ai/v1/chat/completions'
            && $body['stream'] === true
            && $body['model'] === 'grok-4';
    });
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-with-tools-responses');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),

        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => "Search results for: {$query}"),
    ];

    $response = Prism::text()
        ->using('xai', 'grok-4')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
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
        }

        if ($event instanceof ToolResultEvent) {
            $toolResultEvents[] = $event;
        }
    }

    expect($events)->not
        ->toBeEmpty()
        ->and($toolCallEvents)->toHaveCount(2)
        ->and($toolResultEvents)->toHaveCount(2);

    // Verify only one StreamStartEvent and one StreamEndEvent
    $streamStartEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEndEvent);
    expect($streamStartEvents)->toHaveCount(1);
    expect($streamEndEvents)->toHaveCount(1);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.x.ai/v1/chat/completions'
            && isset($body['tools'])
            && $body['stream'] === true
            && $body['model'] === 'grok-4';
    });
});

it('handles max_tokens parameter correctly', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('xai', 'grok-4')
        ->withMaxTokens(1000)
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $chunk) {
        // Process stream
    }

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.x.ai/v1/chat/completions'
            && $body['max_tokens'] === 1000;
    });
});

it('uses default max_tokens when not specified', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('xai', 'grok-4')
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $chunk) {
        // Process stream
    }

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.x.ai/v1/chat/completions'
            && $body['max_tokens'] === 2048;
    });
});

it('can process a complete conversation with multiple tool calls', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-with-tools-responses');

    $tools = [
        Tool::as('get_weather')
            ->for('Get weather information')
            ->withStringParameter('city', 'City name')
            ->using(fn (string $city): string => "The weather in {$city} is 75° and sunny."),

        Tool::as('search')
            ->for('Search for information')
            ->withStringParameter('query', 'The search query')
            ->using(fn (string $query): string => 'Tigers game is at 3pm in Detroit today.'),
    ];

    $response = Prism::text()
        ->using('xai', 'grok-4')
        ->withTools($tools)
        ->withMaxSteps(5)
        ->withPrompt('What time is the Tigers game today and should I wear a coat in Detroit?')
        ->asStream();

    $fullResponse = '';
    $toolCallEvents = [];
    $toolResultEvents = [];

    foreach ($response as $event) {
        if ($event instanceof TextDeltaEvent) {
            $fullResponse .= $event->delta;
        }

        if ($event instanceof ToolCallEvent) {
            $toolCallEvents[] = $event;
        }

        if ($event instanceof ToolResultEvent) {
            $toolResultEvents[] = $event;
        }
    }

    expect($toolCallEvents)
        ->toHaveCount(2)
        ->and($toolResultEvents)
        ->toHaveCount(2);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.x.ai/v1/chat/completions'
            && isset($body['tools'])
            && $body['stream'] === true
            && $body['model'] === 'grok-4';
    });
});

it('handles system prompts correctly', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('xai', 'grok-4')
        ->withSystemPrompt('You are a helpful assistant.')
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $chunk) {
        // Process stream
    }

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return count($body['messages']) === 2
            && $body['messages'][0]['role'] === 'system'
            && $body['messages'][1]['role'] === 'user';
    });
});

it('excludes null parameters from request', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('xai', 'grok-4')
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $chunk) {
        // Process stream
    }

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        // Should not include temperature or top_p when not set
        expect($body)->not
            ->toHaveKey('temperature')
            ->and($body)->not->toHaveKey('top_p');

        return true;
    });
});

it('handles large max_tokens values', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('xai', 'grok-4')
        ->withMaxTokens(8192)
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $chunk) {
        // Process stream
    }

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['max_tokens'] === 8192;
    });
});

it('handles tool choice parameter correctly', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-with-tools-responses');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
    ];

    $response = Prism::text()
        ->using('xai', 'grok-4')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withToolChoice('get_weather')
        ->withPrompt('What is the weather in Detroit?')
        ->asStream();

    foreach ($response as $chunk) {
        // Process stream
    }

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        // First request has specific tool choice, subsequent requests have 'auto' after resetToolChoice()
        if (! isset($body['tool_choice'])) {
            return false;
        }

        // After tool calls, toolChoice is reset to 'auto'
        if ($body['tool_choice'] === 'auto') {
            return true;
        }

        return is_array($body['tool_choice'])
            && $body['tool_choice']['type'] === 'function'
            && $body['tool_choice']['function']['name'] === 'get_weather';
    });
});

it('validates response format structure', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('xai', 'grok-4')
        ->withPrompt('Test')
        ->asStream();

    foreach ($response as $chunk) {
        // Process stream
    }

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        expect($body)->toHaveKeys(['model', 'messages', 'stream', 'max_tokens']);
        expect($body['model'])->toBe('grok-4');
        expect($body['stream'])->toBeTrue();
        expect($body['max_tokens'])->toBe(2048);

        return true;
    });
});

it('can handle thinking/reasoning chunks', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-with-reasoning-responses');

    $response = Prism::text()
        ->using('xai', 'grok-4')
        ->withPrompt('Solve this complex math problem: What is 15 * 23?')
        ->asStream();

    $thinkingContent = '';
    $regularContent = '';
    $thinkingEvents = 0;
    $textEvents = 0;

    foreach ($response as $event) {
        if ($event instanceof ThinkingEvent) {
            $thinkingContent .= $event->delta;
            $thinkingEvents++;
        } elseif ($event instanceof TextDeltaEvent) {
            $regularContent .= $event->delta;
            $textEvents++;
        }
    }

    expect($thinkingEvents)
        ->toBeGreaterThan(0)
        ->and($textEvents)->toBeGreaterThan(0)
        ->and($thinkingContent)->not
        ->toBeEmpty()
        ->and($regularContent)->not
        ->toBeEmpty()
        ->and($thinkingContent)->toContain('multiply')
        ->and($regularContent)->toContain('345');
});

it('can disable thinking content extraction', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-with-reasoning-responses');

    $response = Prism::text()
        ->using('xai', 'grok-4')
        ->withProviderOptions([
            'thinking' => [
                'enabled' => false,
            ],
        ])
        ->withPrompt('Solve this complex math problem: What is 15 * 23?')
        ->asStream();

    $thinkingEvents = 0;
    $textEvents = 0;

    foreach ($response as $event) {
        if ($event instanceof ThinkingEvent) {
            $thinkingEvents++;
        } elseif ($event instanceof TextDeltaEvent) {
            $textEvents++;
        }
    }

    expect($thinkingEvents)->toBe(0); // No thinking events when disabled
    expect($textEvents)->toBeGreaterThan(0); // Still have regular content
});

it('does not truncate 0 mid stream', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-basic-text-responses-with-zeros');

    $response = Prism::text()
        ->using('xai', 'grok-4')
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $events = [];

    $responseId = null;
    $model = null;

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($events)->not
        ->toBeEmpty()
        ->and($text)->toBe('410050');

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.x.ai/v1/chat/completions'
            && $body['stream'] === true
            && $body['model'] === 'grok-4';
    });
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('xai', 'grok-4')
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
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-with-tools-responses');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
    ];

    $response = Prism::text()
        ->using('xai', 'grok-4')
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
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/stream-with-tools-responses');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
    ];

    $response = Prism::text()
        ->using('xai', 'grok-4')
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

function xaiGatingSse(array ...$chunks): string
{
    return collect($chunks)
        ->map(fn (array $chunk): string => 'data: '.json_encode($chunk)."\n\n")
        ->implode('')."data: [DONE]\n\n";
}

/**
 * @return array<string, mixed>
 */
function xaiGatingToolChunk(?string $finishReason = null): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'grok-4',
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
function xaiGatingFinishChunk(string $finishReason): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'grok-4',
        'choices' => [['index' => 0, 'delta' => ['content' => ''], 'finish_reason' => $finishReason]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ];
}

/**
 * @return Collection<int, StreamEvent>
 */
function xaiGatingStream(int &$calls): Collection
{
    return collect(iterator_to_array(Prism::text()
        ->using('xai', 'grok-4')
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
            '*' => Http::response(xaiGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = xaiGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(0)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe($finishReason)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe($finishReason);

        Http::assertSentCount(1);
    })->with([
        'length after the tool call' => [[xaiGatingToolChunk(), xaiGatingFinishChunk('length')], FinishReason::Length],
        'length on the tool call chunk' => [[xaiGatingToolChunk('length')], FinishReason::Length],
        'content filter after the tool call' => [[xaiGatingToolChunk(), xaiGatingFinishChunk('content_filter')], FinishReason::ContentFilter],
        'stream ends without a finish reason' => [[xaiGatingToolChunk()], FinishReason::Stop],
    ]);

    it('runs tool calls once when the step finishes with tool calls', function (array $chunks): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(xaiGatingSse(...$chunks), 200, ['Content-Type' => 'text/event-stream'])
                ->push(xaiGatingSse(xaiGatingFinishChunk('stop')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = xaiGatingStream($calls);
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds->first()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop]);

        Http::assertSentCount(2);
    })->with([
        'tool_calls in a separate chunk' => [[xaiGatingToolChunk(), xaiGatingFinishChunk('tool_calls')]],
        'tool_calls on the tool call chunk' => [[xaiGatingToolChunk('tool_calls')]],
    ]);
});

/**
 * @param  array<string, mixed>  $delta
 * @return array<string, mixed>
 */
function xaiGatingDeltaChunk(array $delta, ?string $finishReason = null): array
{
    return [
        'id' => 'chatcmpl-gating',
        'model' => 'grok-4',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
    ];
}

describe('tool execution gating with mixed chunks', function (): void {
    it('runs tool calls once when the tool-call finish arrives on a chunk with other deltas', function (array $delta, string $streamedDelta): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(xaiGatingSse(xaiGatingToolChunk(), xaiGatingDeltaChunk($delta, 'tool_calls')), 200, ['Content-Type' => 'text/event-stream'])
                ->push(xaiGatingSse(xaiGatingFinishChunk('stop')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = xaiGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop])
            ->and($events->whereInstanceOf([TextDeltaEvent::class, ThinkingEvent::class])->map->delta->implode(''))->toBe($streamedDelta);

        Http::assertSentCount(2);
    })->with([
        'content' => [['content' => 'Checking the weather.'], 'Checking the weather.'],
        'reasoning' => [['reasoning_content' => 'Thinking it over.'], 'Thinking it over.'],
    ]);

    it('never runs tool calls when a non-tool finish arrives on a chunk with other deltas', function (array $delta, string $streamedDelta): void {
        Http::fake([
            '*' => Http::response(xaiGatingSse(xaiGatingToolChunk(), xaiGatingDeltaChunk($delta, 'length')), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = xaiGatingStream($calls);

        expect($calls)->toBe(0)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Length)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::Length)
            ->and($events->whereInstanceOf([TextDeltaEvent::class, ThinkingEvent::class])->map->delta->implode(''))->toBe($streamedDelta);

        Http::assertSentCount(1);
    })->with([
        'content' => [['content' => 'Checking the weather.'], 'Checking the weather.'],
        'reasoning' => [['reasoning_content' => 'Thinking it over.'], 'Thinking it over.'],
    ]);

    it('ends with Stop when the step after a tool call has no finish reason', function (): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(xaiGatingSse(xaiGatingToolChunk(), xaiGatingFinishChunk('tool_calls')), 200, ['Content-Type' => 'text/event-stream'])
                ->push(xaiGatingSse(xaiGatingDeltaChunk(['content' => 'Sunny in Detroit.'])), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = xaiGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map->finishReason->values()->all())
            ->toBe([FinishReason::ToolCalls, FinishReason::Stop]);

        Http::assertSentCount(2);
    });
});

/**
 * @param  array<string, mixed>  $delta
 * @param  array<string, mixed>|null  $usage
 * @return array<string, mixed>
 */
function xaiUsageChunk(array $delta, ?string $finishReason, ?array $usage): array
{
    return [
        'id' => 'chatcmpl-usage',
        'model' => 'grok-4',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
        'usage' => $usage,
    ];
}

describe('usage snapshots', function (): void {
    it('reads usage from a trailing usage-only chunk', function (): void {
        Http::fake([
            '*' => Http::response(xaiGatingSse(
                xaiUsageChunk(['content' => 'Sunny.'], null, null),
                xaiUsageChunk([], 'stop', null),
                [
                    'id' => 'chatcmpl-usage',
                    'model' => 'grok-4',
                    'choices' => [],
                    'usage' => [
                        'prompt_tokens' => 49,
                        'completion_tokens' => 32,
                        'total_tokens' => 264,
                        'prompt_tokens_details' => ['cached_tokens' => 7],
                        'completion_tokens_details' => ['reasoning_tokens' => 183],
                    ],
                ],
            ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = xaiGatingStream($calls);
        $expected = ['prompt_tokens' => 42, 'completion_tokens' => 215, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 7, 'thought_tokens' => 183];

        expect($events->whereInstanceOf(StepFinishEvent::class)->sole()->usage->toArray())->toBe($expected)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe($expected)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop);
    });

    it('keeps the latest cumulative usage snapshot instead of adding them up', function (): void {
        Http::fake([
            '*' => Http::response(xaiGatingSse(
                xaiUsageChunk(['reasoning_content' => 'The'], null, ['prompt_tokens' => 41, 'completion_tokens' => 1, 'total_tokens' => 42]),
                xaiUsageChunk(['content' => 'Ah'], null, ['prompt_tokens' => 41, 'completion_tokens' => 1, 'total_tokens' => 42]),
                xaiUsageChunk(['content' => ','], 'stop', [
                    'prompt_tokens' => 41,
                    'completion_tokens' => 2,
                    'total_tokens' => 43,
                    'prompt_tokens_details' => ['cached_tokens' => 0],
                    'completion_tokens_details' => ['reasoning_tokens' => 0],
                ]),
            ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = xaiGatingStream($calls);

        expect($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
            'prompt_tokens' => 41,
            'completion_tokens' => 2,
            'cache_write_input_tokens' => null,
            'cache_read_input_tokens' => 0,
            'thought_tokens' => 0,
        ]);
    });

    it('adds the usage of every step once across a tool loop', function (): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(xaiGatingSse(
                    xaiGatingToolChunk(),
                    xaiUsageChunk([], 'tool_calls', null),
                    ['id' => 'chatcmpl-usage', 'model' => 'grok-4', 'choices' => [], 'usage' => [
                        'prompt_tokens' => 200,
                        'completion_tokens' => 20,
                        'total_tokens' => 320,
                        'prompt_tokens_details' => ['cached_tokens' => 150],
                        'completion_tokens_details' => ['reasoning_tokens' => 100],
                    ]],
                ), 200, ['Content-Type' => 'text/event-stream'])
                ->push(xaiGatingSse(
                    xaiUsageChunk(['content' => 'Sunny in Detroit.'], 'stop', [
                        'prompt_tokens' => 260,
                        'completion_tokens' => 10,
                        'total_tokens' => 270,
                        'prompt_tokens_details' => ['cached_tokens' => 200],
                    ]),
                ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $calls = 0;
        $events = xaiGatingStream($calls);

        expect($calls)->toBe(1)
            ->and($events->whereInstanceOf(StepFinishEvent::class)->map(fn (StepFinishEvent $event): array => $event->usage->toArray())->values()->all())->toBe([
                ['prompt_tokens' => 50, 'completion_tokens' => 120, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 150, 'thought_tokens' => 100],
                ['prompt_tokens' => 60, 'completion_tokens' => 10, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 200, 'thought_tokens' => null],
            ])
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
                'prompt_tokens' => 110,
                'completion_tokens' => 130,
                'cache_write_input_tokens' => null,
                'cache_read_input_tokens' => 350,
                'thought_tokens' => 100,
            ]);

        Http::assertSentCount(2);
    });

    it('counts the usage of a tool step once when max steps ends the stream on it', function (): void {
        Http::fake([
            '*' => Http::response(xaiGatingSse(
                xaiGatingToolChunk('tool_calls'),
                ['id' => 'chatcmpl-usage', 'model' => 'grok-4', 'choices' => [], 'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 20, 'total_tokens' => 220]],
            ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $events = collect(iterator_to_array(Prism::text()
            ->using('xai', 'grok-4')
            ->withTools([Tool::as('get_weather')->for('Get the weather')->withStringParameter('city', 'The city')->using(fn (string $city): string => "Sunny in {$city}")])
            ->withMaxSteps(1)
            ->withPrompt('What is the weather in Detroit?')
            ->asStream(), false));

        $expected = ['prompt_tokens' => 200, 'completion_tokens' => 20, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => null, 'thought_tokens' => null];

        expect($events->whereInstanceOf(StepFinishEvent::class)->sole()->usage->toArray())->toBe($expected)
            ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe($expected);
    });
});
