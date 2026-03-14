<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Symfony\Component\HttpFoundation\StreamedResponse;

// =============================================================================
// asText() callback tests
// =============================================================================

it('asText calls callback with request and response', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello World'),
    ]);

    $calledWith = null;

    Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test prompt')
        ->asText(function (PendingRequest $request, Response $response) use (&$calledWith): void {
            $calledWith = [$request, $response];
        });

    expect($calledWith[0])->toBeInstanceOf(PendingRequest::class);
    expect($calledWith[1])->toBeInstanceOf(Response::class);
    expect($calledWith[1]->text)->toBe('Hello World');
});

it('asText works without callback', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello World'),
    ]);

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asText();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->text)->toBe('Hello World');
});

it('asText callback can be an invokable class', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Test message'),
    ]);

    $invokable = new class
    {
        public ?Response $receivedResponse = null;

        public function __invoke(PendingRequest $request, Response $response): void
        {
            $this->receivedResponse = $response;
        }
    };

    Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Test')
        ->asText($invokable);

    expect($invokable->receivedResponse)->toBeInstanceOf(Response::class);
    expect($invokable->receivedResponse->text)->toBe('Test message');
});

it('asText still returns response object when callback is set', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Test response'),
    ]);

    $callbackInvoked = false;
    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asText(function (PendingRequest $request, Response $response) use (&$callbackInvoked): void {
            $callbackInvoked = true;
        });

    expect($callbackInvoked)->toBeTrue();
    expect($response)->toBeInstanceOf(Response::class);
    expect($response->text)->toBe('Test response');
});

it('asText callback receives response with tool calls', function (): void {
    $toolCall = new ToolCall('tool-1', 'search', ['query' => 'test']);

    Prism::fake([
        TextResponseFake::make()
            ->withText('Searching')
            ->withToolCalls([$toolCall])
            ->withSteps(collect([
                TextStepFake::make()
                    ->withText('Searching')
                    ->withToolCalls([$toolCall]),
            ])),
    ]);

    $receivedResponse = null;
    Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Search something')
        ->asText(function (PendingRequest $request, Response $response) use (&$receivedResponse): void {
            $receivedResponse = $response;
        });

    expect($receivedResponse)->toBeInstanceOf(Response::class);
    expect($receivedResponse->toolCalls)->toHaveCount(1);
    expect($receivedResponse->toolCalls[0]->name)->toBe('search');
});

// =============================================================================
// asStream() tests (no callback - returns raw Generator)
// =============================================================================

it('asStream returns generator without callback', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello World'),
    ]);

    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    $events = iterator_to_array($stream);

    expect($events)->not->toBeEmpty();
    expect($events)->each->toBeInstanceOf(StreamEvent::class);
});

it('asStream events can be processed manually', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello World'),
    ]);

    $textDeltas = [];
    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    foreach ($stream as $event) {
        if ($event instanceof TextDeltaEvent) {
            $textDeltas[] = $event->delta;
        }
    }

    expect($textDeltas)->not->toBeEmpty();
    expect(implode('', $textDeltas))->toBe('Hello World');
});

// =============================================================================
// asEventStreamResponse() callback tests
// =============================================================================

it('asEventStreamResponse calls callback with collected events at completion', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('SSE test'),
    ]);

    $receivedRequest = null;
    $receivedEvents = null;

    $response = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Test')
        ->asEventStreamResponse(function (PendingRequest $request, Collection $events) use (&$receivedRequest, &$receivedEvents): void {
            $receivedRequest = $request;
            $receivedEvents = $events;
        });

    ob_start();
    $response->getCallback()();
    ob_end_clean();

    expect($receivedRequest)->toBeInstanceOf(PendingRequest::class);
    expect($receivedEvents)->toBeInstanceOf(Collection::class);
    expect($receivedEvents)->not->toBeEmpty();
    expect($receivedEvents)->each->toBeInstanceOf(StreamEvent::class);
})->skip('Output buffering cannot prevent stream output leak');

it('asEventStreamResponse works without callback', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('SSE test'),
    ]);

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asEventStreamResponse();

    expect($response)->toBeInstanceOf(StreamedResponse::class);
});

it('asEventStreamResponse callback receives all text delta events', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello World'),
    ]);

    $collectedText = '';

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asEventStreamResponse(function (PendingRequest $request, Collection $events) use (&$collectedText): void {
            $collectedText = $events
                ->filter(fn (StreamEvent $event): bool => $event instanceof TextDeltaEvent)
                ->map(fn (TextDeltaEvent $event): string => $event->delta)
                ->join('');
        });

    ob_start();
    $response->getCallback()();
    ob_end_clean();

    expect($collectedText)->toBe('Hello World');
})->skip('Output buffering cannot prevent stream output leak');

// =============================================================================
// asDataStreamResponse() callback tests
// =============================================================================

it('asDataStreamResponse calls callback with collected events at completion', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Data stream test'),
    ]);

    $receivedRequest = null;
    $receivedEvents = null;

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asDataStreamResponse(function (PendingRequest $request, Collection $events) use (&$receivedRequest, &$receivedEvents): void {
            $receivedRequest = $request;
            $receivedEvents = $events;
        });

    ob_start();
    $response->getCallback()();
    ob_end_clean();

    expect($receivedRequest)->toBeInstanceOf(PendingRequest::class);
    expect($receivedEvents)->toBeInstanceOf(Collection::class);
    expect($receivedEvents)->not->toBeEmpty();
})->skip('Output buffering cannot prevent stream output leak');

it('asDataStreamResponse works without callback', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Data stream test'),
    ]);

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asDataStreamResponse();

    expect($response)->toBeInstanceOf(StreamedResponse::class);
});

// =============================================================================
// Tool call scenarios with streaming callbacks
// =============================================================================

it('asEventStreamResponse callback receives tool call events', function (): void {
    $toolCall = new ToolCall('tool-123', 'search', ['query' => 'test']);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()
                ->withText('Let me search')
                ->withToolCalls([$toolCall]),
        ])),
    ]);

    $hasToolCallEvent = false;

    $response = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Search something')
        ->asEventStreamResponse(function (PendingRequest $request, Collection $events) use (&$hasToolCallEvent): void {
            $hasToolCallEvent = $events->contains(
                fn (StreamEvent $event): bool => $event instanceof ToolCallEvent
            );
        });

    ob_start();
    $response->getCallback()();
    ob_end_clean();

    expect($hasToolCallEvent)->toBeTrue();
})->skip('Output buffering cannot prevent stream output leak');

it('asEventStreamResponse callback receives tool result events', function (): void {
    $toolCall = new ToolCall('tool-1', 'calculator', ['x' => 5, 'y' => 3]);
    $toolResult = new ToolResult('tool-1', 'calculator', ['x' => 5, 'y' => 3], ['result' => 8]);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()->withToolCalls([$toolCall]),
            TextStepFake::make()->withToolResults([$toolResult]),
        ])),
    ]);

    $hasToolResultEvent = false;

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Calculate')
        ->asEventStreamResponse(function (PendingRequest $request, Collection $events) use (&$hasToolResultEvent): void {
            $hasToolResultEvent = $events->contains(
                fn (StreamEvent $event): bool => $event instanceof ToolResultEvent
            );
        });

    ob_start();
    $response->getCallback()();
    ob_end_clean();

    expect($hasToolResultEvent)->toBeTrue();
})->skip('Output buffering cannot prevent stream output leak');

// =============================================================================
// Invokable class with streaming callbacks
// =============================================================================

it('asEventStreamResponse callback can be an invokable class', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Test message'),
    ]);

    $invokable = new class
    {
        /** @var Collection<int, StreamEvent>|null */
        public ?Collection $events = null;

        /**
         * @param  Collection<int, StreamEvent>  $events
         */
        public function __invoke(PendingRequest $request, Collection $events): void
        {
            $this->events = $events;
        }
    };

    $response = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Test')
        ->asEventStreamResponse($invokable);

    ob_start();
    $response->getCallback()();
    ob_end_clean();

    expect($invokable->events)->toBeInstanceOf(Collection::class);
    expect($invokable->events)->not->toBeEmpty();
})->skip('Output buffering cannot prevent stream output leak');

// =============================================================================
// Edge cases
// =============================================================================

it('handles empty response with streaming callback', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText(''),
    ]);

    $receivedEvents = null;

    $response = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Test')
        ->asEventStreamResponse(function (PendingRequest $request, Collection $events) use (&$receivedEvents): void {
            $receivedEvents = $events;
        });

    ob_start();
    $response->getCallback()();
    ob_end_clean();

    expect($receivedEvents)->toBeInstanceOf(Collection::class);
})->skip('Output buffering cannot prevent stream output leak');

it('works with unicode and special characters in streaming callback', function (): void {
    $unicodeText = 'Hello World';

    Prism::fake([
        TextResponseFake::make()->withText($unicodeText),
    ]);

    $receivedText = '';

    $response = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Unicode test')
        ->asEventStreamResponse(function (PendingRequest $request, Collection $events) use (&$receivedText): void {
            $receivedText = $events
                ->filter(fn (StreamEvent $event): bool => $event instanceof TextDeltaEvent)
                ->map(fn (TextDeltaEvent $event): string => $event->delta)
                ->join('');
        });

    ob_start();
    $response->getCallback()();
    ob_end_clean();

    expect($receivedText)->toBe($unicodeText);
})->skip('Output buffering cannot prevent stream output leak');

// =============================================================================
// generate() (deprecated) tests
// =============================================================================

it('generate calls callback with request and response', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello World'),
    ]);

    $calledWith = null;

    Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test prompt')
        ->generate(function (PendingRequest $request, Response $response) use (&$calledWith): void {
            $calledWith = [$request, $response];
        });

    expect($calledWith[0])->toBeInstanceOf(PendingRequest::class);
    expect($calledWith[1])->toBeInstanceOf(Response::class);
});

it('generate works without callback', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello World'),
    ]);

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->generate();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->text)->toBe('Hello World');
});
