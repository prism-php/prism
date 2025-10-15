<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\Streaming\Events\CitationEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\ProviderTool;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'fake-key'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-basic-text');

    $response = Prism::text()
        ->using('anthropic', 'claude-3-7-sonnet-20250219')
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
    expect($text)->not->toBeEmpty();

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $body['stream'] === true;
    });
});

it('can return usage with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-basic-text');

    $response = Prism::text()
        ->using('anthropic', 'claude-3-7-sonnet-20250219')
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

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect((array) $lastEvent->usage)->toBe([
        'promptTokens' => 11,
        'completionTokens' => 104,
        'cacheWriteInputTokens' => 0,
        'cacheReadInputTokens' => 0,
        'thoughtTokens' => null,
    ]);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $body['stream'] === true;
    });
});

describe('tools', function (): void {
    it('can generate text using tools with streaming', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-tools');

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
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withTools($tools)
            ->withMaxSteps(3)
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asStream();

        $text = '';
        $events = [];
        $toolCallFound = false;
        $toolResults = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof ToolCallEvent) {
                $toolCallFound = true;
                expect($event->toolCall->name)->not->toBeEmpty();
                expect($event->toolCall->arguments())->toBeArray();
            }

            if ($event instanceof ToolResultEvent) {
                $toolResults[] = $event;
            }

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }
        }

        expect($events)->not->toBeEmpty();
        expect($toolCallFound)->toBeTrue('Expected to find at least one tool call in the stream');

        $lastEvent = end($events);
        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->finishReason)->toBe(FinishReason::Stop);

        // Verify the HTTP request
        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && isset($body['tools'])
                && $body['stream'] === true;
        });
    });

    it('can process a complete conversation with multiple tool calls', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-multi-tool-conversation');

        $tools = [
            Tool::as('weather')
                ->for('Get weather information')
                ->withStringParameter('city', 'City name')
                ->using(fn (string $city): string => "The weather in {$city} is 75° and sunny."),

            Tool::as('search')
                ->for('Search for information')
                ->withStringParameter('query', 'The search query')
                ->using(fn (string $query): string => 'Tigers game is at 3pm in Detroit today.'),
        ];

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withTools($tools)
            ->withMaxSteps(5) // Allow multiple tool call rounds
            ->withPrompt('What time is the Tigers game today and should I wear a coat in Detroit?')
            ->asStream();

        $fullResponse = '';
        $toolCallCount = 0;

        foreach ($response as $event) {
            if ($event instanceof ToolCallEvent) {
                $toolCallCount++;
            }

            if ($event instanceof TextDeltaEvent) {
                $fullResponse .= $event->delta;
            }
        }

        expect($toolCallCount)->toBeGreaterThanOrEqual(1);
        expect($fullResponse)->not->toBeEmpty();

        // Verify we made multiple requests for a conversation with tool calls
        Http::assertSentCount(3);
    });

    it('emits individual ToolCall and ToolResult events during streaming', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-tools');

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
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withTools($tools)
            ->withMaxSteps(3)
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asStream();

        $events = [];
        $toolCallEvents = [];
        $toolResultEvents = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof ToolCallEvent) {
                $toolCallEvents[] = $event;
            }

            if ($event instanceof ToolResultEvent) {
                $toolResultEvents[] = $event;
            }
        }

        expect($events)->not->toBeEmpty();
        expect($toolCallEvents)->not->toBeEmpty('Expected to find at least one ToolCall event');
        expect($toolResultEvents)->not->toBeEmpty('Expected to find at least one ToolResult event');

        // Verify ToolCall events have the expected structure
        $firstToolCallEvent = $toolCallEvents[0];
        expect($firstToolCallEvent->toolCall->name)->not->toBeEmpty();
        expect($firstToolCallEvent->toolCall->arguments())->toBeArray();
        expect($firstToolCallEvent->toolCall->id)->not->toBeEmpty();

        // Verify ToolResult events have the expected structure
        $firstToolResultEvent = $toolResultEvents[0];
        expect($firstToolResultEvent->toolResult->result)->not->toBeEmpty();
        expect($firstToolResultEvent->success)->toBeTrue();
    });
});

describe('citations', function (): void {
    it('emits CitationEvent and includes citations in StreamEndEvent', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-citations');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withProviderOptions(['citations' => true])
            ->withMessages([
                (new UserMessage(
                    content: 'What color is the grass and sky?',
                    additionalContent: [
                        Document::fromText('The grass is green. The sky is blue.'),
                    ]
                )),
            ])
            ->asStream();

        $text = '';
        $events = [];
        $citationEvents = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }

            if ($event instanceof CitationEvent) {
                $citationEvents[] = $event;
            }
        }

        $lastEvent = end($events);

        // Check that citation events were emitted
        expect($citationEvents)->not->toBeEmpty();
        expect($citationEvents[0])->toBeInstanceOf(CitationEvent::class);
        expect($citationEvents[0]->citation)->toBeInstanceOf(Citation::class);
        expect($citationEvents[0]->messageId)->not->toBeEmpty();

        // Check that the StreamEndEvent contains citations
        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->citations)->toBeArray();
        expect($lastEvent->citations)->not->toBeEmpty();
        expect($lastEvent->citations[0])->toBeInstanceOf(MessagePartWithCitations::class);
        expect($lastEvent->citations[0]->citations[0])->toBeInstanceOf(Citation::class);
        expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
    });

    it('emits citation events with proper message and block context', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-citations');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withProviderOptions(['citations' => true])
            ->withMessages([
                (new UserMessage(
                    content: 'What color is the grass and sky?',
                    additionalContent: [
                        Document::fromText('The grass is green. The sky is blue.'),
                    ]
                )),
            ])
            ->asStream();

        $citationEvents = [];

        foreach ($response as $event) {
            if ($event instanceof CitationEvent) {
                $citationEvents[] = $event;
            }
        }

        // Verify citation events have proper context
        expect($citationEvents)->not->toBeEmpty();
        expect($citationEvents[0]->messageId)->not->toBeEmpty();
        expect($citationEvents[0]->blockIndex)->toBeInt();
        expect($citationEvents[0]->citation)->toBeInstanceOf(Citation::class);
    });

    it('handles web search citations in events', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-web-search-citations');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withPrompt('What is the weather like in London UK today?')
            ->withProviderTools([new ProviderTool(type: 'web_search_20250305', name: 'web_search')])
            ->asStream();

        $text = '';
        $events = [];
        $citationEvents = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }

            if ($event instanceof CitationEvent) {
                $citationEvents[] = $event;
            }
        }

        $lastEvent = end($events);

        // Check that citation events were emitted for web search results
        expect($citationEvents)->not->toBeEmpty();
        expect($citationEvents[0])->toBeInstanceOf(CitationEvent::class);
        expect($citationEvents[0]->citation)->toBeInstanceOf(Citation::class);

        // Verify web search citations have URL source type
        expect($citationEvents[0]->citation->sourceType->value)->toBe('url');
        expect($citationEvents[0]->citation->source)->toBeString();

        // Check that the StreamEndEvent contains citations
        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->citations)->not->toBeEmpty();
        expect($lastEvent->citations[0])->toBeInstanceOf(MessagePartWithCitations::class);
        expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
    });
});

describe('thinking', function (): void {
    it('yields thinking events', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-extended-thinking');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withPrompt('What is the meaning of life?')
            ->withProviderOptions(['thinking' => ['enabled' => true]])
            ->asStream();

        $events = collect($response);

        expect($events->where(fn ($event): bool => $event->type() === StreamEventType::ThinkingStart)->sole())
            ->toBeInstanceOf(ThinkingStartEvent::class);

        $thinkingDeltas = $events->where(
            fn (StreamEvent $event): bool => $event->type() === StreamEventType::ThinkingDelta
        );

        $thinkingDeltas
            ->each(function (StreamEvent $event): void {
                expect($event)->toBeInstanceOf(ThinkingEvent::class);
            });

        expect($thinkingDeltas->count())->toBeGreaterThan(10);

        expect($thinkingDeltas->first()->delta)->not->toBeEmpty();

        expect($events->where(fn ($event): bool => $event->type() === StreamEventType::ThinkingComplete)->sole())
            ->toBeInstanceOf(ThinkingCompleteEvent::class);
    });

    it('can process streams with thinking enabled with custom budget', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-extended-thinking');

        $customBudget = 2048;
        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withPrompt('What is the meaning of life?')
            ->withProviderOptions([
                'thinking' => [
                    'enabled' => true,
                    'budgetTokens' => $customBudget,
                ],
            ])
            ->asStream();

        collect($response);

        // Verify custom budget was sent
        Http::assertSent(function (Request $request) use ($customBudget): bool {
            $body = json_decode($request->body(), true);

            return isset($body['thinking'])
                && $body['thinking']['type'] === 'enabled'
                && $body['thinking']['budget_tokens'] === $customBudget;
        });
    });
});

describe('exception handling', function (): void {
    it('throws a PrismRateLimitedException with a 429 response code', function (): void {
        Http::fake([
            '*' => Http::response(
                status: 429,
            ),
        ])->preventStrayRequests();

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withPrompt('Who are you?')
            ->asStream();

        foreach ($response as $event) {
            // Don't remove me rector!
        }
    })->throws(PrismRateLimitedException::class);

    it('sets the correct data on the RateLimitException', function (): void {
        $requests_reset = Carbon::now()->addSeconds(30);

        Http::fake([
            '*' => Http::response(
                status: 429,
                headers: [
                    'anthropic-ratelimit-requests-limit' => 1000,
                    'anthropic-ratelimit-requests-remaining' => 500,
                    'anthropic-ratelimit-requests-reset' => $requests_reset->toISOString(),
                    'anthropic-ratelimit-input-tokens-limit' => 80000,
                    'anthropic-ratelimit-input-tokens-remaining' => 0,
                    'anthropic-ratelimit-input-tokens-reset' => Carbon::now()->addSeconds(60)->toISOString(),
                    'anthropic-ratelimit-output-tokens-limit' => 16000,
                    'anthropic-ratelimit-output-tokens-remaining' => 15000,
                    'anthropic-ratelimit-output-tokens-reset' => Carbon::now()->addSeconds(5)->toISOString(),
                    'anthropic-ratelimit-tokens-limit' => 96000,
                    'anthropic-ratelimit-tokens-remaining' => 15000,
                    'anthropic-ratelimit-tokens-reset' => Carbon::now()->addSeconds(5)->toISOString(),
                    'retry-after' => 40,
                ]
            ),
        ])->preventStrayRequests();

        try {
            $response = Prism::text()
                ->using('anthropic', 'claude-3-5-sonnet-20240620')
                ->withPrompt('Hello world!')
                ->asStream();

            foreach ($response as $event) {
                // Don't remove me rector!
            }
        } catch (PrismRateLimitedException $e) {
            expect($e->retryAfter)->toEqual(40);
            expect($e->rateLimits)->toHaveCount(4);
            expect($e->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
            expect($e->rateLimits[0]->name)->toEqual('requests');
            expect($e->rateLimits[0]->limit)->toEqual(1000);
            expect($e->rateLimits[0]->remaining)->toEqual(500);
            expect($e->rateLimits[0]->resetsAt)->toEqual($requests_reset);

            expect($e->rateLimits[1]->name)->toEqual('input-tokens');
            expect($e->rateLimits[1]->limit)->toEqual(80000);
            expect($e->rateLimits[1]->remaining)->toEqual(0);
        }
    });

    it('throws an overloaded exception if the Anthropic responds with a 529', function (): void {
        Http::fake([
            '*' => Http::response(
                status: 529,
            ),
        ])->preventStrayRequests();

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Hello world!')
            ->asStream();

        foreach ($response as $event) {
            // Don't remove me rector!
        }

    })->throws(PrismProviderOverloadedException::class);

    it('throws a request too large exception if the Anthropic responds with a 413', function (): void {
        Http::fake([
            '*' => Http::response(
                status: 413,
            ),
        ])->preventStrayRequests();

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Hello world!')
            ->asStream();

        foreach ($response as $event) {
            // Don't remove me rector!
        }

    })->throws(PrismRequestTooLargeException::class);
});

describe('basic stream events', function (): void {
    it('can generate text with a basic stream', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-basic-text');

        $response = Prism::text()
            ->using('anthropic', 'claude-3-7-sonnet-20250219')
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
        expect($text)->not->toBeEmpty();

        // Verify the HTTP request
        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $body['stream'] === true;
        });
    });
});
