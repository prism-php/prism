<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
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
    $textStartEvents = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof TextStartEvent);
    expect($textStartEvents)->toHaveCount(1);

    // Check we have TextDeltaEvents
    $textDeltaEvents = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof TextDeltaEvent);
    expect($textDeltaEvents)->not->toBeEmpty();

    // Check we have TextCompleteEvent
    $textCompleteEvents = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof TextCompleteEvent);
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

    // Check for StreamEndEvent with usage
    $streamEndEvents = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof StreamEndEvent);
    expect($streamEndEvents)->not->toBeEmpty();

    $lastStreamEnd = array_values($streamEndEvents)[array_key_last(array_values($streamEndEvents))];
    expect($lastStreamEnd->usage)->not->toBeNull();
    expect($lastStreamEnd->usage->promptTokens)->toBeGreaterThan(0);
    expect($lastStreamEnd->usage->completionTokens)->toBeGreaterThan(0);
});

it('can stream text with empty parameters tool calls when using gpt-5', function () {
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

    $streamEndEvents = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof StreamEndEvent);
    expect($streamEndEvents)->not->toBeEmpty();
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
    $thinkingStartEvents = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof ThinkingStartEvent);
    expect($thinkingStartEvents)->toHaveCount(1);

    // Check for ThinkingEvent
    expect($thinkingEvents)->toHaveCount(1);
    expect($thinkingEvents[0]->delta)->toContain('math problem');

    // Check text was assembled
    expect($text)->toBe('The answer to 2 + 2 is 4.');

    // Check for usage with reasoning tokens
    $streamEndEvents = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof StreamEndEvent);
    expect($streamEndEvents)->toHaveCount(1);

    $streamEndEvent = array_values($streamEndEvents)[0];
    expect($streamEndEvent->usage)->not->toBeNull();
    expect($streamEndEvent->usage->thoughtTokens)->toBe(12);
});
