<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Streaming\StreamCollector;
use Prism\Prism\Streaming\StreamedMessages;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Tests\Fixtures\FixtureResponse;

/**
 * @param  array<StreamEvent>  $events
 * @return Generator<StreamEvent>
 */
function createCollectorEventGenerator(array $events): Generator
{
    foreach ($events as $event) {
        yield $event;
    }
}

it('yields all events from stream as pass-through', function (): void {
    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'gpt-4', 'openai'),
        new TextStartEvent('evt-2', 1640995201, 'msg-1'),
        new TextDeltaEvent('evt-3', 1640995202, 'Hello', 'msg-1'),
        new TextDeltaEvent('evt-4', 1640995203, ' World', 'msg-1'),
        new StreamEndEvent('evt-5', 1640995204, FinishReason::Stop),
    ];

    $collector = new StreamCollector(createCollectorEventGenerator($events));
    $yieldedEvents = iterator_to_array($collector->collect());

    expect($yieldedEvents)->toHaveCount(5);
    expect($yieldedEvents[0])->toBe($events[0]);
    expect($yieldedEvents[1])->toBe($events[1]);
    expect($yieldedEvents[2])->toBe($events[2]);
    expect($yieldedEvents[3])->toBe($events[3]);
    expect($yieldedEvents[4])->toBe($events[4]);
});

it('accumulates text from multiple text delta events', function (): void {
    $messages = null;

    $events = [
        new TextStartEvent('evt-1', 1640995200, 'msg-1'),
        new TextDeltaEvent('evt-2', 1640995201, 'Hello', 'msg-1'),
        new TextDeltaEvent('evt-3', 1640995202, ' ', 'msg-1'),
        new TextDeltaEvent('evt-4', 1640995203, 'World', 'msg-1'),
        new StreamEndEvent('evt-5', 1640995204, FinishReason::Stop),
    ];

    $collector = new StreamCollector(
        createCollectorEventGenerator($events),
        null,
        function ($request, Collection $collected) use (&$messages): void {
            $messages = $collected;
        }
    );

    iterator_to_array($collector->collect());

    expect($messages)->toBeInstanceOf(Collection::class);
    expect($messages)->toHaveCount(1);
    expect($messages->first())->toBeInstanceOf(AssistantMessage::class);
    expect($messages->first()->content)->toBe('Hello World');
});

it('collects tool calls from tool call events', function (): void {
    $messages = null;

    $toolCall1 = new ToolCall('tool-1', 'search', ['q' => 'test']);
    $toolCall2 = new ToolCall('tool-2', 'calculate', ['x' => 5, 'y' => 10]);

    $events = [
        new TextStartEvent('evt-1', 1640995200, 'msg-1'),
        new TextDeltaEvent('evt-2', 1640995201, 'Let me help', 'msg-1'),
        new ToolCallEvent('evt-3', 1640995202, $toolCall1, 'msg-1'),
        new ToolCallEvent('evt-4', 1640995203, $toolCall2, 'msg-1'),
        new StreamEndEvent('evt-5', 1640995204, FinishReason::Stop),
    ];

    $collector = new StreamCollector(
        createCollectorEventGenerator($events),
        null,
        function ($request, Collection $collected) use (&$messages): void {
            $messages = $collected;
        }
    );

    iterator_to_array($collector->collect());

    expect($messages)->toHaveCount(1);
    expect($messages->first())->toBeInstanceOf(AssistantMessage::class);
    expect($messages->first()->content)->toBe('Let me help');
    expect($messages->first()->toolCalls)->toHaveCount(2);
    expect($messages->first()->toolCalls[0])->toBe($toolCall1);
    expect($messages->first()->toolCalls[1])->toBe($toolCall2);
});

it('collects tool results from tool result events', function (): void {
    $messages = null;

    $toolCall = new ToolCall('tool-1', 'search', ['q' => 'test']);
    $toolResult = new ToolResult('tool-1', 'search', ['q' => 'test'], ['result' => 'found']);

    $events = [
        new TextStartEvent('evt-1', 1640995200, 'msg-1'),
        new ToolCallEvent('evt-2', 1640995201, $toolCall, 'msg-1'),
        new StreamEndEvent('evt-3', 1640995202, FinishReason::ToolCalls),
        new ToolResultEvent('evt-4', 1640995203, $toolResult, 'msg-2', true),
        new StreamEndEvent('evt-5', 1640995204, FinishReason::Stop),
    ];

    $collector = new StreamCollector(
        createCollectorEventGenerator($events),
        null,
        function ($request, Collection $collected) use (&$messages): void {
            $messages = $collected;
        }
    );

    iterator_to_array($collector->collect());

    expect($messages)->toHaveCount(2);
    expect($messages[0])->toBeInstanceOf(AssistantMessage::class);
    expect($messages[0]->toolCalls)->toHaveCount(1);
    expect($messages[1])->toBeInstanceOf(ToolResultMessage::class);
    expect($messages[1]->toolResults)->toHaveCount(1);
    expect($messages[1]->toolResults[0])->toBe($toolResult);
});

it('handles multi-step conversation with multiple message pairs', function (): void {
    $messages = null;

    $toolCall1 = new ToolCall('tool-1', 'search', ['q' => 'Laravel']);
    $toolResult1 = new ToolResult('tool-1', 'search', ['q' => 'Laravel'], ['result' => 'PHP Framework']);
    $toolCall2 = new ToolCall('tool-2', 'calculate', ['x' => 5]);
    $toolResult2 = new ToolResult('tool-2', 'calculate', ['x' => 5], ['result' => 10]);

    $events = [
        new TextStartEvent('evt-1', 1640995200, 'msg-1'),
        new TextDeltaEvent('evt-2', 1640995201, 'First response', 'msg-1'),
        new ToolCallEvent('evt-3', 1640995202, $toolCall1, 'msg-1'),
        new ToolResultEvent('evt-4', 1640995203, $toolResult1, 'msg-2', true),
        new TextStartEvent('evt-5', 1640995204, 'msg-3'),
        new TextDeltaEvent('evt-6', 1640995205, 'Second response', 'msg-3'),
        new ToolCallEvent('evt-7', 1640995206, $toolCall2, 'msg-3'),
        new ToolResultEvent('evt-8', 1640995207, $toolResult2, 'msg-4', true),
        new TextStartEvent('evt-9', 1640995208, 'msg-5'),
        new TextDeltaEvent('evt-10', 1640995209, 'Final response', 'msg-5'),
        new StreamEndEvent('evt-11', 1640995210, FinishReason::Stop),
    ];

    $collector = new StreamCollector(
        createCollectorEventGenerator($events),
        null,
        function ($request, Collection $collected) use (&$messages): void {
            $messages = $collected;
        }
    );

    iterator_to_array($collector->collect());

    expect($messages)->toHaveCount(5);
    expect($messages[0])->toBeInstanceOf(AssistantMessage::class);
    expect($messages[0]->content)->toBe('First response');
    expect($messages[0]->toolCalls)->toHaveCount(1);
    expect($messages[1])->toBeInstanceOf(ToolResultMessage::class);
    expect($messages[1]->toolResults)->toHaveCount(1);
    expect($messages[2])->toBeInstanceOf(AssistantMessage::class);
    expect($messages[2]->content)->toBe('Second response');
    expect($messages[2]->toolCalls)->toHaveCount(1);
    expect($messages[3])->toBeInstanceOf(ToolResultMessage::class);
    expect($messages[3]->toolResults)->toHaveCount(1);
    expect($messages[4])->toBeInstanceOf(AssistantMessage::class);
    expect($messages[4]->content)->toBe('Final response');
    expect($messages[4]->toolCalls)->toBeEmpty();
});

it('works without callback', function (): void {
    $events = [
        new TextStartEvent('evt-1', 1640995200, 'msg-1'),
        new TextDeltaEvent('evt-2', 1640995201, 'Hello', 'msg-1'),
        new StreamEndEvent('evt-3', 1640995202, FinishReason::Stop),
    ];

    $collector = new StreamCollector(createCollectorEventGenerator($events));
    $yieldedEvents = iterator_to_array($collector->collect());

    expect($yieldedEvents)->toHaveCount(3);
});

it('handles empty stream', function (): void {
    $messages = null;

    $collector = new StreamCollector(
        createCollectorEventGenerator([]),
        null,
        function ($request, Collection $collected) use (&$messages): void {
            $messages = $collected;
        }
    );

    $yieldedEvents = iterator_to_array($collector->collect());

    expect($yieldedEvents)->toBeEmpty();
    expect($messages)->toBeNull();
});

it('handles stream with only stream end event', function (): void {
    $messages = null;

    $events = [
        new StreamEndEvent('evt-1', 1640995200, FinishReason::Stop),
    ];

    $collector = new StreamCollector(
        createCollectorEventGenerator($events),
        null,
        function ($request, Collection $collected) use (&$messages): void {
            $messages = $collected;
        }
    );

    iterator_to_array($collector->collect());

    expect($messages)->toBeInstanceOf(Collection::class);
    expect($messages)->toBeEmpty();
});

it('handles stream with text only', function (): void {
    $messages = null;

    $events = [
        new TextStartEvent('evt-1', 1640995200, 'msg-1'),
        new TextDeltaEvent('evt-2', 1640995201, 'Hello World', 'msg-1'),
        new StreamEndEvent('evt-3', 1640995202, FinishReason::Stop),
    ];

    $collector = new StreamCollector(
        createCollectorEventGenerator($events),
        null,
        function ($request, Collection $collected) use (&$messages): void {
            $messages = $collected;
        }
    );

    iterator_to_array($collector->collect());

    expect($messages)->toHaveCount(1);
    expect($messages->first())->toBeInstanceOf(AssistantMessage::class);
    expect($messages->first()->content)->toBe('Hello World');
    expect($messages->first()->toolCalls)->toBeEmpty();
});

it('handles stream with tool calls only', function (): void {
    $messages = null;

    $toolCall = new ToolCall('tool-1', 'search', ['q' => 'test']);

    $events = [
        new TextStartEvent('evt-1', 1640995200, 'msg-1'),
        new ToolCallEvent('evt-2', 1640995201, $toolCall, 'msg-1'),
        new StreamEndEvent('evt-3', 1640995202, FinishReason::ToolCalls),
    ];

    $collector = new StreamCollector(
        createCollectorEventGenerator($events),
        null,
        function ($request, Collection $collected) use (&$messages): void {
            $messages = $collected;
        }
    );

    iterator_to_array($collector->collect());

    expect($messages)->toHaveCount(1);
    expect($messages->first())->toBeInstanceOf(AssistantMessage::class);
    expect($messages->first()->content)->toBe('');
    expect($messages->first()->toolCalls)->toHaveCount(1);
});

it('handles multiple tool results in single message', function (): void {
    $messages = null;

    $toolResult1 = new ToolResult('tool-1', 'search', ['q' => 'test1'], ['result' => 'found1']);
    $toolResult2 = new ToolResult('tool-2', 'search', ['q' => 'test2'], ['result' => 'found2']);

    $events = [
        new ToolResultEvent('evt-1', 1640995200, $toolResult1, 'msg-1', true),
        new ToolResultEvent('evt-2', 1640995201, $toolResult2, 'msg-1', true),
        new StreamEndEvent('evt-3', 1640995202, FinishReason::Stop),
    ];

    $collector = new StreamCollector(
        createCollectorEventGenerator($events),
        null,
        function ($request, Collection $collected) use (&$messages): void {
            $messages = $collected;
        }
    );

    iterator_to_array($collector->collect());

    expect($messages)->toHaveCount(1);
    expect($messages->first())->toBeInstanceOf(ToolResultMessage::class);
    expect($messages->first()->toolResults)->toHaveCount(2);
    expect($messages->first()->toolResults[0])->toBe($toolResult1);
    expect($messages->first()->toolResults[1])->toBe($toolResult2);
});

it('invokes callback with collection instance', function (): void {
    $receivedType = null;

    $events = [
        new TextStartEvent('evt-1', 1640995200, 'msg-1'),
        new TextDeltaEvent('evt-2', 1640995201, 'Test', 'msg-1'),
        new StreamEndEvent('evt-3', 1640995202, FinishReason::Stop),
    ];

    $collector = new StreamCollector(
        createCollectorEventGenerator($events),
        null,
        function ($request, Collection $collected) use (&$receivedType): void {
            $receivedType = $collected::class;
        }
    );

    iterator_to_array($collector->collect());

    expect($receivedType)->toBe(Collection::class);
});

it('preserves event data integrity during pass-through', function (): void {
    $toolCall = new ToolCall('tool-1', 'search', ['q' => 'test', 'limit' => 10]);
    $toolResult = new ToolResult('tool-1', 'search', ['q' => 'test', 'limit' => 10], ['items' => [1, 2, 3]]);

    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'gpt-4', 'openai'),
        new TextStartEvent('evt-2', 1640995201, 'msg-1'),
        new TextDeltaEvent('evt-3', 1640995202, 'Testing', 'msg-1'),
        new ToolCallEvent('evt-4', 1640995203, $toolCall, 'msg-1'),
        new ToolResultEvent('evt-5', 1640995204, $toolResult, 'msg-2', true),
        new StreamEndEvent('evt-6', 1640995205, FinishReason::Stop),
    ];

    $collector = new StreamCollector(createCollectorEventGenerator($events));
    $yieldedEvents = iterator_to_array($collector->collect());

    expect($yieldedEvents[0]->model)->toBe('gpt-4');
    expect($yieldedEvents[0]->provider)->toBe('openai');
    expect($yieldedEvents[2]->delta)->toBe('Testing');
    expect($yieldedEvents[3]->toolCall->name)->toBe('search');
    expect($yieldedEvents[3]->toolCall->arguments())->toBe(['q' => 'test', 'limit' => 10]);
    expect($yieldedEvents[4]->toolResult->result)->toBe(['items' => [1, 2, 3]]);
    expect($yieldedEvents[5]->finishReason)->toBe(FinishReason::Stop);
});

/*
 * RESTORED FOR THE CONSUMERS BUILT ON IT (fancy, 2026-10-21). Upstream #1043 deleted StreamCollector, the fork's
 * v0.132.0 shipped that deletion undeclared, and prism-harness 0.13.0 -- which builds every streamed transcript with
 * it -- died with "Class not found". The tests above are the class's own, restored unchanged. This one enters as the
 * harness does, over a real multi-step provider stream in the post-#1043 shape (step events, one StreamEndEvent).
 */
it('over a real multi-step tool stream, hands the harness the same messages StreamedMessages builds', function (): void {
    config()->set('prism.providers.anthropic.api_key', 'fake-key');
    Event::fake();
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-interleaved-thinking-tools');

    $weather = Tool::as('get_weather')
        ->for('Get current weather for a city')
        ->withStringParameter('city', 'The city')
        ->using(fn (string $city): string => $city === 'London' ? '14°C' : '22°C');

    $generation = Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withTools([$weather])
        ->withMaxSteps(3)
        ->withPrompt('Which is warmer, London or Rome?');

    $collected = null;
    // Exactly as prism-harness 0.13.0 builds it (src/AgentRuntime.php).
    $collector = new StreamCollector(
        $generation->asStream(),
        null,
        function (mixed $pending, Collection $messages, Response $response) use (&$collected): void {
            $collected = ['messages' => $messages->all(), 'response' => $response];
        },
    );

    $events = iterator_to_array($collector->collect(), false);

    expect($collected)->not->toBeNull();
    $expected = StreamedMessages::fromEvents($events);
    expect(array_map(fn (Message $message): string => $message::class, $collected['messages']))->toBe([
        AssistantMessage::class,
        ToolResultMessage::class,
        AssistantMessage::class,
    ])
        ->and($collected['messages'])->toEqual($expected->all());

    $end = collect($events)->last(fn (StreamEvent $event): bool => $event instanceof StreamEndEvent);
    expect($collected['response']->finishReason)->toBe($end->finishReason)
        ->and($collected['response']->usage)->toBe($end->usage)
        ->and($collected['response']->toolResults)->toHaveCount(count($collected['messages'][1]->toolResults));
});

it('builds the callback response from the steps\' usage when the stream end reports none', function (): void {
    $response = null;
    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'claude-sonnet-4-5', 'anthropic'),
        new TextStartEvent('evt-2', 1640995201, 'msg-1'),
        new TextDeltaEvent('evt-3', 1640995202, 'Sunny', 'msg-1'),
        new StepFinishEvent('evt-4', 1640995203, new Usage(11, 1), finishReason: FinishReason::Error),
        new StreamEndEvent('evt-5', 1640995204, FinishReason::Error),
    ];

    $collector = new StreamCollector(createCollectorEventGenerator($events), null,
        function ($request, Collection $messages, Response $collected) use (&$response): void {
            $response = $collected;
        });
    iterator_to_array($collector->collect());

    // Unknown at the end is not zero: the response keeps what did arrive, and
    // its Error finish says the total is incomplete.
    expect($response->usage->promptTokens)->toBe(11)
        ->and($response->usage->completionTokens)->toBe(1)
        ->and($response->finishReason)->toBe(FinishReason::Error);
});
