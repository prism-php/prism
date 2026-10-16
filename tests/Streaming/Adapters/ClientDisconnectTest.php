<?php

declare(strict_types=1);

namespace Tests\Streaming\Adapters;

use Generator;
use Illuminate\Support\Collection;
use Mockery;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Streaming\Adapters\DataProtocolAdapter;
use Prism\Prism\Streaming\Adapters\SSEAdapter;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Streaming\StreamedMessages;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\ValueObjects\Artifact;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;
use Throwable;

/**
 * @return array<string, mixed>
 */
function toolStepReplayContent(): array
{
    return [
        'thinking' => 'Two cities, two lookups.',
        'thinking_signature' => 'sig-abc',
        'content_blocks' => [
            ['type' => 'thinking', 'thinking' => 'Two cities, two lookups.', 'signature' => 'sig-abc'],
            ['type' => 'text', 'text' => 'Checking both cities.'],
            ['type' => 'tool_use', 'id' => 'call-london', 'name' => 'get_weather', 'input' => ['city' => 'London']],
            ['type' => 'tool_use', 'id' => 'call-rome', 'name' => 'get_weather', 'input' => ['city' => 'Rome']],
        ],
    ];
}

function weatherResult(string $toolCallId, string $city, string $weather): ToolResult
{
    return new ToolResult($toolCallId, 'get_weather', ['city' => $city], $weather);
}

/**
 * A two-step stream: a tool step that looks up two cities, then the answer.
 *
 * @return array<int, StreamEvent|Throwable>
 */
function twoStepToolStream(): array
{
    return [
        new StreamStartEvent('stream-start', 1, 'claude-sonnet-5-5', 'anthropic'),
        new StepStartEvent('step-1-start', 1),
        new TextStartEvent('text-start', 1, 'msg-1'),
        new TextDeltaEvent('text-delta', 1, 'Checking both cities.', 'msg-1'),
        new TextCompleteEvent('text-complete', 1, 'msg-1'),
        new ToolCallEvent('call-london-event', 1, new ToolCall('call-london', 'get_weather', ['city' => 'London']), 'msg-1'),
        new ToolCallEvent('call-rome-event', 1, new ToolCall('call-rome', 'get_weather', ['city' => 'Rome']), 'msg-1'),
        new ToolResultEvent('result-london', 2, weatherResult('call-london', 'London', '14°C'), 'msg-1'),
        new ToolResultEvent('result-rome', 2, weatherResult('call-rome', 'Rome', '22°C'), 'msg-1'),
        new StepFinishEvent('step-1-finish', 2, new Usage(10, 5), toolStepReplayContent(), FinishReason::ToolCalls),
        new StepStartEvent('step-2-start', 3),
        new TextStartEvent('answer-start', 3, 'msg-2'),
        new TextDeltaEvent('answer-delta', 3, 'London is 14°C, Rome is 22°C.', 'msg-2'),
        new TextCompleteEvent('answer-complete', 3, 'msg-2'),
        new StepFinishEvent('step-2-finish', 3, new Usage(20, 8), finishReason: FinishReason::Stop),
        new StreamEndEvent('stream-end', 3, FinishReason::Stop),
    ];
}

/**
 * The connection drops as the event with the given ID is yielded. Every event
 * the adapter pulls is recorded, so a test can tell how far it advanced.
 *
 * @param  array<int, StreamEvent|Throwable>  $events
 */
function disconnectingAt(string $eventId, array $events, ClientConnection $connection): Generator
{
    foreach ($events as $event) {
        if ($event instanceof Throwable) {
            throw $event;
        }

        $connection->pulledEventIds[] = $event->id;

        if ($event->id === $eventId) {
            $connection->aborted = true;
        }

        yield $event;
    }
}

function disconnectableAdapter(string $protocol, ClientConnection $connection): SSEAdapter|DataProtocolAdapter
{
    return match ($protocol) {
        'sse' => new class($connection) extends SSEAdapter
        {
            public function __construct(protected ClientConnection $connection) {}

            protected function connectionAborted(): bool
            {
                return $this->connection->aborted;
            }
        },
        'data protocol' => new class($connection) extends DataProtocolAdapter
        {
            public function __construct(protected ClientConnection $connection) {}

            protected function connectionAborted(): bool
            {
                return $this->connection->aborted;
            }
        },
    };
}

/**
 * @return array{events: Collection<int, StreamEvent>, output: string}
 */
function streamThrough(SSEAdapter|DataProtocolAdapter $adapter, Generator $events): array
{
    $collectedEvents = new Collection;

    $response = $adapter(
        $events,
        Mockery::mock(PendingRequest::class),
        function (PendingRequest $request, Collection $events) use (&$collectedEvents): void {
            $collectedEvents = $events;
        }
    );

    $output = '';
    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    try {
        $response->getCallback()();
    } finally {
        ob_end_flush();
    }

    return ['events' => $collectedEvents, 'output' => $output];
}

/**
 * @param  Collection<int, StreamEvent>  $events
 * @return array<int, string>
 */
function eventIds(Collection $events): array
{
    return $events->map(fn (StreamEvent $event): string => $event->id)->all();
}

class ClientConnection
{
    public bool $aborted = false;

    /** @var array<int, string> */
    public array $pulledEventIds = [];
}

dataset('disconnecting adapters', ['sse', 'data protocol']);

it('collects the rest of a tool step when the client disconnects on a tool result', function (string $protocol): void {
    $connection = new ClientConnection;

    $result = streamThrough(
        disconnectableAdapter($protocol, $connection),
        disconnectingAt('result-london', twoStepToolStream(), $connection)
    );

    expect(eventIds($result['events']))->toBe([
        'stream-start', 'step-1-start', 'text-start', 'text-delta', 'text-complete',
        'call-london-event', 'call-rome-event', 'result-london', 'result-rome', 'step-1-finish',
    ]);

    $messages = StreamedMessages::fromEvents($result['events']);

    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->content)->toBe('Checking both cities.')
        ->and($messages[0]->toolCalls)->toHaveCount(2)
        ->and($messages[0]->additionalContent)->toBe(toolStepReplayContent())
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and(array_map(fn (ToolResult $toolResult): string => $toolResult->result, $messages[1]->toolResults))
        ->toBe(['14°C', '22°C']);
})->with('disconnecting adapters');

it('never pulls past the step finish event, which would send the next request', function (string $protocol): void {
    $connection = new ClientConnection;

    streamThrough(
        disconnectableAdapter($protocol, $connection),
        disconnectingAt('result-london', twoStepToolStream(), $connection)
    );

    expect(end($connection->pulledEventIds))->toBe('step-1-finish')
        ->and($connection->pulledEventIds)->not->toContain('step-2-start');
})->with('disconnecting adapters');

it('sends no output for the events it collects after the disconnect', function (string $protocol, array $absentOutput): void {
    $connection = new ClientConnection;

    $result = streamThrough(
        disconnectableAdapter($protocol, $connection),
        disconnectingAt('result-london', twoStepToolStream(), $connection)
    );

    expect($result['output'])->toContain('Checking both cities.');

    foreach ($absentOutput as $fragment) {
        expect($result['output'])->not->toContain($fragment);
    }
})->with([
    'sse' => ['sse', ['event: tool_result', 'event: step_finish']],
    'data protocol' => ['data protocol', ['tool-output-available', 'finish-step']],
]);

it('collects the rest of a tool step when the client disconnects on an artifact', function (string $protocol): void {
    $connection = new ClientConnection;
    $events = twoStepToolStream();

    array_splice($events, 8, 0, [
        new ArtifactEvent('artifact-london', 2, new Artifact(base64_encode('chart'), 'image/png'), 'call-london', 'get_weather', 'msg-1'),
    ]);

    $result = streamThrough(
        disconnectableAdapter($protocol, $connection),
        disconnectingAt('artifact-london', $events, $connection)
    );

    expect(array_slice(eventIds($result['events']), -4))
        ->toBe(['result-london', 'artifact-london', 'result-rome', 'step-1-finish'])
        ->and(end($connection->pulledEventIds))->toBe('step-1-finish');
})->with('disconnecting adapters');

it('stops at once when the client disconnects outside a step\'s tool results', function (string $protocol, string $abortedOn): void {
    $connection = new ClientConnection;

    $result = streamThrough(
        disconnectableAdapter($protocol, $connection),
        disconnectingAt($abortedOn, twoStepToolStream(), $connection)
    );

    expect($result['events']->last()->id)->toBe($abortedOn)
        ->and(end($connection->pulledEventIds))->toBe($abortedOn);
})->with('disconnecting adapters')->with([
    'during text' => 'text-delta',
    'on a tool call' => 'call-rome-event',
    'on the step finish' => 'step-1-finish',
]);

it('stops when the stream ends before the step finishes', function (string $protocol): void {
    $connection = new ClientConnection;

    $result = streamThrough(
        disconnectableAdapter($protocol, $connection),
        disconnectingAt('result-london', array_slice(twoStepToolStream(), 0, 8), $connection)
    );

    expect($result['events']->last()->id)->toBe('result-london')
        ->and($result['events']->last(fn (StreamEvent $event): bool => $event instanceof ErrorEvent))->toBeNull();
})->with('disconnecting adapters');

it('reports an exception raised while collecting the rest of the step', function (): void {
    $connection = new ClientConnection;
    $events = twoStepToolStream();

    array_splice($events, 8, 2, [new RuntimeException('Tool output could not be read')]);

    $result = streamThrough(
        disconnectableAdapter('data protocol', $connection),
        disconnectingAt('result-london', $events, $connection)
    );

    $lastEvent = $result['events']->last();

    expect($lastEvent)->toBeInstanceOf(ErrorEvent::class)
        ->and($lastEvent->message)->toBe('Tool output could not be read')
        ->and($result['events'][$result['events']->count() - 2]->id)->toBe('result-london')
        ->and($result['output'])->toContain('"errorText":"Tool output could not be read"')
        ->and($result['output'])->toEndWith("data: [DONE]\n\n");
});
