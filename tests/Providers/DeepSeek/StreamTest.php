<?php

declare(strict_types=1);

namespace Prism\tests\Providers\DeepSeek;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.deepseek.api_key', env('DEEPSEEK_API_KEY'));
    config()->set('prism.providers.deepseek.url', env('DEEPSEEK_URL', 'https://api.deepseek.com'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'deepseek/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::DeepSeek, 'deepseek-chat')
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
        ->and($text)->not->toBeEmpty();

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.deepseek.com/chat/completions'
            && $body['stream'] === true
            && $body['model'] === 'deepseek-chat';
    });
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'deepseek/stream-with-tools');

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
        ->using(Provider::DeepSeek, 'deepseek-chat')
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

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.deepseek.com/chat/completions'
            && isset($body['tools'])
            && $body['stream'] === true
            && $body['model'] === 'deepseek-chat';
    });
});

it('handles max_tokens parameter correctly', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'deepseek/stream-max-tokens');

    $response = Prism::text()
        ->using(Provider::DeepSeek, 'deepseek-chat')
        ->withMaxTokens(1000)
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $event) {
        // Process stream
    }

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.deepseek.com/chat/completions'
            && $body['max_tokens'] === 1000;
    });
});

it('handles system prompts correctly', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'deepseek/stream-system-prompt');

    $response = Prism::text()
        ->using(Provider::DeepSeek, 'deepseek-chat')
        ->withSystemPrompt('You are a helpful assistant.')
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $event) {
        // Process stream
    }

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return count($body['messages']) === 2
            && $body['messages'][0]['role'] === 'system'
            && $body['messages'][1]['role'] === 'user';
    });
});

it('can handle reasoning/thinking tokens in streaming', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'deepseek/stream-with-reasoning');

    $response = Prism::text()
        ->using(Provider::DeepSeek, 'deepseek-reasoner')
        ->withPrompt('Solve this complex math problem: What is 4 * 8?')
        ->asStream();

    $thinkingContent = '';
    $regularContent = '';
    $thinkingEvents = 0;
    $textDeltaEvents = 0;

    foreach ($response as $event) {
        if ($event instanceof ThinkingEvent) {
            $thinkingContent .= $event->delta;
            $thinkingEvents++;
        } elseif ($event instanceof TextDeltaEvent) {
            $regularContent .= $event->delta;
            $textDeltaEvents++;
        }
    }

    expect($thinkingEvents)
        ->toBeGreaterThan(0)
        ->and($textDeltaEvents)->toBeGreaterThan(0)
        ->and($thinkingContent)->not
        ->toBeEmpty()
        ->and($regularContent)->not
        ->toBeEmpty()
        ->and($thinkingContent)->toContain('answer')
        ->and($regularContent)->toContain('32');
});
