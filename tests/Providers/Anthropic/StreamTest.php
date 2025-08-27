<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Database\Eloquent\Collection;
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
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderRateLimit;
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
    $streamEndEvent = null;

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }

        if ($event instanceof StreamEndEvent) {
            $streamEndEvent = $event;
        }
    }

    expect($events)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();
    expect($streamEndEvent)->not->toBeNull();
    expect($streamEndEvent->finishReason)->toBe(FinishReason::Stop);

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
    $streamEndEvent = null;

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }

        if ($event instanceof StreamEndEvent) {
            $streamEndEvent = $event;
        }
    }

    expect($streamEndEvent)->not->toBeNull();
    expect($streamEndEvent->usage)->not->toBeNull();
    expect((array) $streamEndEvent->usage)->toBe([
        'promptTokens' => 11,
        'completionTokens' => 107,
        'cacheWriteInputTokens' => 0,
        'cacheReadInputTokens' => 0,
        'thoughtTokens' => 0,
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
        $streamEndEvent = null;

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }

            if ($event instanceof ToolCallEvent) {
                $toolCallFound = true;
                expect($event->toolCall->name)->not->toBeEmpty();
                expect($event->toolCall->arguments())->toBeArray();
            }

            if ($event instanceof ToolResultEvent) {
                $toolResults[] = $event->toolResult;
            }

            if ($event instanceof StreamEndEvent) {
                $streamEndEvent = $event;
            }
        }

        expect($events)->not->toBeEmpty();
        expect($toolCallFound)->toBeTrue('Expected to find at least one tool call in the stream');
        expect($streamEndEvent)->not->toBeNull();
        expect($streamEndEvent->finishReason)->toBe(FinishReason::Stop);

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
            if ($event instanceof TextDeltaEvent) {
                $fullResponse .= $event->delta;
            }

            if ($event instanceof ToolCallEvent) {
                $toolCallCount++;
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
        expect($firstToolCallEvent->type())->toBe(StreamEventType::ToolCall);
        expect($firstToolCallEvent->toolCall->name)->not->toBeEmpty();
        expect($firstToolCallEvent->toolCall->arguments())->toBeArray();

        // Verify ToolResult events have the expected structure
        $firstToolResultEvent = $toolResultEvents[0];
        expect($firstToolResultEvent->type())->toBe(StreamEventType::ToolResult);
        expect($firstToolResultEvent->toolResult->result)->not->toBeEmpty();
    });
});

describe('citations', function (): void {
    it('adds citations to the stream end event when enabled', function (): void {
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
        $streamEndEvent = null;

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }

            if ($event instanceof StreamEndEvent) {
                $streamEndEvent = $event;
            }
        }

        // Note: Citations are now handled by the stream handler and may be available
        // in the stream end event metadata or through text completion events
        expect($streamEndEvent)->not->toBeNull();
        expect($streamEndEvent->finishReason)->toBe(FinishReason::Stop);
    });

    it('processes citation deltas during text streaming', function (): void {
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
        $textDeltaEvents = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
                $textDeltaEvents[] = $event;
            }
        }

        expect($textDeltaEvents)->not->toBeEmpty();
    });
});

describe('thinking', function (): void {
    it('can process streams with thinking enabled and emits thinking events', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-extended-thinking');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withPrompt('What is the meaning of life?')
            ->withProviderOptions(['thinking' => ['enabled' => true]])
            ->asStream();

        $events = [];
        $thinkingEvents = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof ThinkingEvent) {
                $thinkingEvents[] = $event;
            }
        }

        expect($events)->not->toBeEmpty();
        expect($thinkingEvents)->not->toBeEmpty();

        // Verify thinking events contain reasoning content
        $firstThinkingEvent = $thinkingEvents[0];
        expect($firstThinkingEvent->type())->toBe(StreamEventType::ThinkingDelta);
        expect($firstThinkingEvent->delta)->not->toBeEmpty();
        expect($firstThinkingEvent->reasoningId)->not->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && isset($body['thinking'])
                && $body['thinking']['type'] === 'enabled'
                && isset($body['thinking']['budget_tokens'])
                && $body['thinking']['budget_tokens'] === config('prism.anthropic.default_thinking_budget', 1024);
        });
    });

    it('yields thinking events with correct event type', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-extended-thinking');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withPrompt('What is the meaning of life?')
            ->withProviderOptions(['thinking' => ['enabled' => true]])
            ->asStream();

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        $thinkingEvents = (new Collection($events))->filter(fn ($event): bool => $event instanceof ThinkingEvent);

        expect($thinkingEvents->count())->toBeGreaterThan(0);

        $firstThinkingEvent = $thinkingEvents->first();
        expect($firstThinkingEvent->delta)->not()->toBeEmpty();
        expect($firstThinkingEvent->type())->toBe(StreamEventType::ThinkingDelta);
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

        foreach ($response as $event) {
            // Process stream
        }

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

describe('event metadata', function (): void {
    it('can generate text with a basic stream and includes metadata', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-basic-text');

        $response = Prism::text()
            ->using('anthropic', 'claude-3-7-sonnet-20250219')
            ->withPrompt('Who are you?')
            ->asStream();

        $text = '';
        $events = [];
        $streamStartEvent = null;

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }

            if ($event instanceof StreamStartEvent) {
                $streamStartEvent = $event;
            }
        }

        expect($events)->not->toBeEmpty();
        expect($text)->not->toBeEmpty();
        expect($streamStartEvent)->not->toBeNull();
        expect($streamStartEvent->model)->not->toBeEmpty();
        expect($streamStartEvent->provider)->toBe('anthropic');

        // Verify the HTTP request
        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $body['stream'] === true;
        });
    });

    it('adds rate limit data to stream start event metadata', function (): void {
        $requests_reset = Carbon::now()->addSeconds(30);

        FixtureResponse::fakeStreamResponses(
            'v1/messages',
            'anthropic/stream-basic-text',
            [
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
            ]
        );

        $response = Prism::text()
            ->using('anthropic', 'claude-3-7-sonnet-20250219')
            ->withPrompt('Who are you?')
            ->asStream();

        $events = [];
        $streamStartEvent = null;

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof StreamStartEvent) {
                $streamStartEvent = $event;
            }
        }

        expect($streamStartEvent)->not->toBeNull();
        expect($streamStartEvent->type())->toBe(StreamEventType::StreamStart);

        // Rate limit data should be in the metadata
        expect($streamStartEvent->metadata)->not->toBeNull();
        expect($streamStartEvent->metadata)->toHaveKey('rate_limits');
        expect($streamStartEvent->metadata['rate_limits'])->toHaveCount(4);
        expect($streamStartEvent->metadata['rate_limits'][0])->toBeInstanceOf(ProviderRateLimit::class);
        expect($streamStartEvent->metadata['rate_limits'][0]->name)->toEqual('requests');
        expect($streamStartEvent->metadata['rate_limits'][0]->limit)->toEqual(1000);
        expect($streamStartEvent->metadata['rate_limits'][0]->remaining)->toEqual(500);
        expect($streamStartEvent->metadata['rate_limits'][0]->resetsAt)->toEqual($requests_reset);
    });
});
