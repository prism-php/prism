<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\Usage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'fake-key'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
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

    expect($events)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();
    expect($model)->not->toBeNull();

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.openai.com/v1/responses'
            && $body['stream'] === true;
    });
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-with-tools-responses');

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
        ->using('openai', 'gpt-4o')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStream();

    $text = '';
    $events = [];
    $toolCalls = [];
    $toolResults = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof ToolCallEvent) {
            $toolCalls[] = $event->toolCall;
        }

        if ($event instanceof ToolResultEvent) {
            $toolResults[] = $event->toolResult;
        }

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($events)->not->toBeEmpty();
    expect($toolCalls)->toHaveCount(2);
    expect($toolResults)->toHaveCount(2);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.openai.com/v1/responses'
            && isset($body['tools'])
            && $body['stream'] === true;
    });
});

it('can process a complete conversation with multiple tool calls', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-multi-tool-conversation-responses');

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
        ->using('openai', 'gpt-4o')
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

    expect($toolCallCount)->toBe(2);
    expect($fullResponse)->not->toBeEmpty();

    // Verify we made multiple requests for a conversation with tool calls
    Http::assertSentCount(2);
});

it('can process a complete conversation with multiple tool calls for reasoning models', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-multi-tool-conversation-responses-reasoning');
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
        ->using('openai', 'o3-mini')
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

    expect($toolCallCount)->toBe(2);
    expect($fullResponse)->not->toBeEmpty();

    // Verify we made multiple requests for a conversation with tool calls
    Http::assertSentCount(3);
});

it('can process a complete conversation with multiple tool calls for reasoning models that require past reasoning', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-multi-tool-conversation-responses-reasoning-past-reasoning');
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
        ->using('openai', 'o4-mini')
        ->withProviderOptions([
            'reasoning' => [
                'effort' => 'low',
                'summary' => 'detailed',
            ],
        ])
        ->withTools($tools)
        ->withMaxSteps(5) // Allow multiple tool call rounds
        ->withPrompt('What time is the Tigers game today and should I wear a coat in Detroit?')
        ->asStream();

    $answerText = '';
    $toolCallCount = 0;
    $reasoningText = '';
    /** @var Usage[] $usage */
    $usage = [];

    foreach ($response as $event) {
        if ($event instanceof ToolCallEvent) {
            $toolCallCount++;
        }

        if ($event instanceof ThinkingEvent) {
            $reasoningText .= $event->delta;
        }

        if ($event instanceof TextDeltaEvent) {
            $answerText .= $event->delta;
        }

        if ($event instanceof StreamEndEvent && $event->usage) {
            $usage[] = $event->usage;
        }
    }

    expect($toolCallCount)->toBe(2);
    expect($answerText)->not->toBeEmpty();
    expect($reasoningText)->not->toBeEmpty();

    // Verify reasoning usage
    expect($usage[0]->thoughtTokens)->toBeGreaterThan(0);

    // Verify we made multiple requests for a conversation with tool calls
    Http::assertSentCount(3);
});

it('can process a complete conversation with provider tool', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-with-provider-tool');
    $tools = [
        new ProviderTool('web_search_preview'),
    ];

    $response = Prism::text()
        ->using('openai', 'o4-mini')
        ->withProviderTools($tools)
        ->withMaxSteps(5) // Allow multiple tool call rounds
        ->withPrompt('Search the web to retrieve the exact multiplicator to turn centimeters into inches.')
        ->asStream();

    $answerText = '';
    $toolCallCount = 0;
    /** @var Usage[] $usage */
    $usage = [];

    foreach ($response as $event) {
        if ($event instanceof ToolCallEvent) {
            $toolCallCount++;
        }

        if ($event instanceof TextDeltaEvent) {
            $answerText .= $event->delta;
        }

        if ($event instanceof StreamEndEvent && $event->usage) {
            $usage[] = $event->usage;
        }
    }

    expect($toolCallCount)->toBe(0); // We currently don't count provider tools as tool calls.
    expect($answerText)->not->toBeEmpty();

    // Verify we made multiple requests for a conversation with tool calls
    Http::assertSentCount(1);
});

it('can pass parallel tool call setting', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-multi-tool-conversation-responses');

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
        ->using('openai', 'gpt-4o')
        ->withTools($tools)
        ->withProviderOptions(['parallel_tool_calls' => false])
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

    expect($toolCallCount)->toBe(2);
    expect($fullResponse)->not->toBeEmpty();

    Http::assertSent(fn (Request $request): bool => $request->data()['parallel_tool_calls'] === false);
});

it('emits usage information', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $event) {
        if ($event instanceof StreamEndEvent && $event->usage) {
            expect($event->usage->promptTokens)->toBeGreaterThan(0);
            expect($event->usage->completionTokens)->toBeGreaterThan(0);
        }
    }
});

it('can accept falsy parameters', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-falsy-argument-conversation-responses');

    $modelTool = Tool::as('get_models')
        ->for('Returns info about of available models')
        ->withNumberParameter('modelId', 'Id of the model to load. Returns all models if null', false)
        ->using(fn (int $modelId): string => "The model {$modelId} is the funniest of all");

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Can you tell me more about the model with id 0 ?')
        ->withTools([$modelTool])
        ->withMaxSteps(2)
        ->asStream();

    foreach ($response as $chunk) {
        ob_flush();
        flush();
    }
})->throwsNoExceptions();

it('throws a PrismException on an unknown error', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-unknown-error-responses');

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $chunk) {
        // Read stream
    }
})->throws(PrismException::class, 'Sending to model gpt-4 failed. Code: unknown-error. Message: Foobar');

it('sends reasoning effort when defined', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-reasoning-effort');

    $response = Prism::text()
        ->using('openai', 'gpt-5')
        ->withPrompt('Who are you?')
        ->withProviderOptions([
            'reasoning' => [
                'effort' => 'low',
            ],
        ])
        ->asStream();

    // process stream
    collect($response);

    Http::assertSent(fn (Request $request): bool => $request->data()['reasoning']['effort'] === 'low');
});

it('exposes response_id in stream end event', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asStream();

    $streamEndEvent = null;

    foreach ($response as $event) {
        if ($event instanceof StreamEndEvent) {
            $streamEndEvent = $event;
        }
    }

    expect($streamEndEvent)->not->toBeNull()
        ->and($streamEndEvent->additionalContent)->toHaveKey('response_id')
        ->and($streamEndEvent->additionalContent['response_id'])->toBe('resp_6859a4ad7d3c81999e9e02548c91e2a8077218073e9990d3');

    $array = $streamEndEvent->toArray();

    expect($array)->toHaveKey('response_id')
        ->and($array['response_id'])->toBe('resp_6859a4ad7d3c81999e9e02548c91e2a8077218073e9990d3');
});
