<?php

declare(strict_types=1);

namespace Tests\Providers\VertexAI;

use Illuminate\Http\Client\Request;
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
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.vertexai.project_id', 'test-project');
    config()->set('prism.providers.vertexai.location', 'us-central1');
    config()->set('prism.providers.vertexai.api_key', 'test-key-1234');
});

it('can generate text stream with a basic prompt', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/stream-basic-text');

    $origModel = 'gemini-2.0-flash';
    $response = Prism::text()
        ->using(Provider::VertexAI, $origModel)
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

    expect($lastEvent->usage)
        ->not->toBeNull()
        ->and($lastEvent->usage->promptTokens)->toBe(21)
        ->and($lastEvent->usage->completionTokens)->toBe(47);

    Http::assertSent(function (Request $request): bool {
        $url = $request->url();

        expect($url)->toContain('aiplatform.googleapis.com')
            ->and($url)->toContain('streamGenerateContent');

        return true;
    });
});

it('can generate text stream using tools', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/stream-with-tools-thought');

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
        ->using(Provider::VertexAI, 'gemini-2.5-flash')
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
        ->and($toolResults)->not->toBeEmpty()
        ->and($toolResults[0]->result)->toBe('The weather will be 75° and sunny in San Francisco')
        ->and($text)->toContain('The current weather in San Francisco is 75°F and sunny. You likely won\'t need a coat, but you might want to bring a light jacket just in case it gets breezy or cools down later.');

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->usage->promptTokens)->toBe(278);
    expect($lastEvent->usage->completionTokens)->toBe(44);
});

it('yields ToolCall events before ToolResult events', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/stream-with-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::VertexAI, 'gemini-2.5-flash')
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

    $streamStartEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEndEvent);

    expect($streamStartEvents)->toHaveCount(1);
    expect($streamEndEvents)->toHaveCount(1);
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::VertexAI, 'gemini-2.5-flash')
        ->withPrompt('Explain how AI works')
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

    expect($streamStartIndex)->toBeLessThan($stepStartIndex);
    expect($stepStartIndex)->toBeLessThan($stepFinishIndex);
    expect($stepFinishIndex)->toBeLessThan($streamEndIndex);
});

it('emits multiple step events with tool calls', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/stream-with-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::VertexAI, 'gemini-2.5-flash')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What is the weather in San Francisco?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    $stepStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent);
    $stepFinishEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent);

    expect(count($stepStartEvents))->toBeGreaterThanOrEqual(2);
    expect(count($stepFinishEvents))->toBeGreaterThanOrEqual(2);
    expect(count($stepStartEvents))->toBe(count($stepFinishEvents));
});

it('can generate text stream using multiple parallel tool calls', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/stream-with-multiple-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => 'The weather will be '.($city === 'San Francisco' ? 50 : 75)."° and sunny in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::VertexAI, 'gemini-2.5-flash')
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

    expect($toolCalls[0]->reasoningId)->not->toBeNull();
    expect($toolCalls[1]->reasoningId)->not->toBeNull();
    expect($toolCalls[0]->reasoningId)->toBe($toolCalls[1]->reasoningId);
});
