<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
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
    $providerToolEvents = [];

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

        if ($event instanceof ProviderToolEvent) {
            $providerToolEvents[] = $event;
        }
    }

    expect($events)->not->toBeEmpty();
    expect($toolCalls)->toHaveCount(2);
    expect($toolResults)->toHaveCount(2);
    expect($providerToolEvents)->toBeEmpty();

    // Verify only one StreamStartEvent and one StreamEndEvent
    $streamStartEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEndEvent);
    expect($streamStartEvents)->toHaveCount(1);
    expect($streamEndEvents)->toHaveCount(1);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.openai.com/v1/responses'
            && isset($body['tools'])
            && $body['stream'] === true;
    });
});

it('emits ToolCallDelta events during streaming', function (): void {
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
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStream();

    $toolCallDeltaEvents = [];

    foreach ($response as $event) {
        if ($event instanceof ToolCallDeltaEvent) {
            $toolCallDeltaEvents[] = $event;
        }
    }

    expect($toolCallDeltaEvents)->not->toBeEmpty('Expected to find at least one ToolCallDeltaEvent event');

    // Verify ToolCallDelta events have the expected structure
    $firstToolCallDeltaEvent = $toolCallDeltaEvents[0];
    expect($firstToolCallDeltaEvent->id)->not->toBeEmpty();
    expect($firstToolCallDeltaEvent->timestamp)->not->toBeEmpty()->toBeInt();
    expect($firstToolCallDeltaEvent->toolId)->not->toBeEmpty();
    expect($firstToolCallDeltaEvent->toolName)->not->toBeEmpty();
    expect($firstToolCallDeltaEvent->delta)->toBeString();

    // Verify concatenated deltas from ToolCallDelta events of the first tool call is a valid json
    $paramsJsonString = collect($toolCallDeltaEvents)
        ->filter(fn (ToolCallDeltaEvent $event): bool => $event->toolName === $firstToolCallDeltaEvent->toolName)
        ->map(fn (ToolCallDeltaEvent $event): string => $event->delta)
        ->join('');

    expect($paramsJsonString)->toBeJson();
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

it('can process streaming with image_generation provider tool', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-with-image-generation');

    $tools = [
        new ProviderTool('image_generation'),
    ];

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withProviderTools($tools)
        ->withMaxSteps(5)
        ->withPrompt('Generate an image of a sunset over mountains')
        ->asStream();

    $answerText = '';
    $providerToolEvents = [];
    $imageData = null;

    foreach ($response as $event) {
        if ($event instanceof TextDeltaEvent) {
            $answerText .= $event->delta;
        }

        if ($event instanceof ProviderToolEvent) {
            $providerToolEvents[] = $event;

            if ($event->toolType === 'image_generation_call' && $event->status === 'completed') {
                $imageData = $event->data['result'] ?? null;
            }
        }
    }

    expect($providerToolEvents)->not->toBeEmpty();

    $statuses = array_map(fn (ProviderToolEvent $e): string => $e->status, $providerToolEvents);
    expect($statuses)->toContain('in_progress');
    expect($statuses)->toContain('generating');
    expect($statuses)->toContain('completed');

    foreach ($providerToolEvents as $event) {
        expect($event->toolType)->toBe('image_generation_call');
        expect($event->itemId)->toStartWith('ig_');
        expect($event->eventKey())->toStartWith('provider_tool_event.image_generation_call.');
    }

    expect($imageData)->not->toBeNull();
    expect($imageData)->toStartWith('iVBORw0KGgo');

    expect($answerText)->not->toBeEmpty();

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
        if (ob_get_level() > 0) {
            ob_flush();
        }
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

it('uses meta to set service_tier', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-reasoning-effort');

    $serviceTier = 'priority';

    $response = Prism::text()
        ->using('openai', 'gpt-5')
        ->withPrompt('Who are you?')
        ->withProviderOptions([
            'service_tier' => $serviceTier,
        ])
        ->asStream();

    // process stream
    collect($response);

    Http::assertSent(fn (Request $request): bool => $request->data()['service_tier'] === $serviceTier);
});

it('filters service_tier if null', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-reasoning-effort');

    $response = Prism::text()
        ->using('openai', 'gpt-5')
        ->withPrompt('Who are you?')
        ->withProviderOptions([
            'service_tier' => null,
        ])
        ->asStream();

    // process stream
    collect($response);

    Http::assertSent(function (Request $request): bool {
        expect($request->data())->not()->toHaveKey('service_tier');

        return true; // Assertion will fail
    });
});

it('uses meta to set text_verbosity', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    $textVerbosity = 'medium';

    $response = Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('Who are you?')
        ->withProviderOptions([
            'text_verbosity' => $textVerbosity,
        ])
        ->asStream();

    // process stream
    collect($response);

    Http::assertSent(function (Request $request) use ($textVerbosity): true {
        $body = json_decode($request->body(), true);

        expect(data_get($body, 'text.verbosity'))->toBe($textVerbosity);

        return true;
    });
});

it('filters text_verbosity if null', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    $response = Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('Who are you?')
        ->withProviderOptions([
            'text_verbosity' => null,
        ])
        ->asText();

    // process stream
    collect($response);

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect($body)->not()->toHaveKey('text.verbosity');

        return true;
    });
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-basic-text-responses');

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
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
    expect(count($stepStartEvents))->toBeGreaterThanOrEqual(2)
        ->and(count($stepFinishEvents))->toBeGreaterThanOrEqual(2)
        ->and(count($stepStartEvents))->toBe(count($stepFinishEvents));
});

it('sends StreamEndEvent using tools with streaming and max steps = 1', function (): void {
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

it('emits TextStart and TextComplete events for each output item', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-multiple-output-items');

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Test multiple output items')
        ->asStream();

    $events = [];
    foreach ($response as $event) {
        $events[] = $event;
    }

    $textStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof TextStartEvent);
    $textCompleteEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof TextCompleteEvent);
    $textDeltaEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof TextDeltaEvent);

    expect($textStartEvents)->toHaveCount(2);
    expect($textCompleteEvents)->toHaveCount(2);
    expect($textDeltaEvents)->toHaveCount(4);

    $textStartIndices = array_keys($textStartEvents);
    $textCompleteIndices = array_keys($textCompleteEvents);

    expect($textStartIndices[0])->toBeLessThan($textCompleteIndices[0]);
    expect($textCompleteIndices[0])->toBeLessThan($textStartIndices[1]);
    expect($textStartIndices[1])->toBeLessThan($textCompleteIndices[1]);
});
