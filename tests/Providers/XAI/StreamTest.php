<?php

declare(strict_types=1);

namespace Tests\Providers\XAI;

use Illuminate\Http\Client\Request;
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
