<?php

declare(strict_types=1);

namespace Tests\Streaming;

use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Providers\Anthropic\Handlers\Text as AnthropicText;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap as OpenAIMessageMap;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Streaming\StreamedMessages;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Tool as PrismTool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fixtures\FixtureResponse;

function streamedWeatherTool(): PrismTool
{
    return Tool::as('get_weather')
        ->for('Get current weather for a city')
        ->withStringParameter('city', 'The city')
        ->using(fn (string $city): string => match ($city) {
            'London' => '14°C',
            'Rome' => '22°C',
            default => '18°C and cloudy',
        });
}

function sendDiscardingOutput(StreamedResponse $response): void
{
    ob_start(fn (): string => '');

    try {
        $response->sendContent();
    } finally {
        ob_end_clean();
    }
}

/**
 * Runs the request through one of the documented streaming transports and
 * returns the events its completion callback received.
 *
 * @return Collection<int, StreamEvent>
 */
function eventsFromStreamCallback(string $transport, PendingRequest $request): Collection
{
    $received = null;

    $callback = function (PendingRequest $request, Collection $events) use (&$received): void {
        $received = $events;
    };

    match ($transport) {
        'event stream' => sendDiscardingOutput($request->asEventStreamResponse($callback)),
        'data stream' => sendDiscardingOutput($request->asDataStreamResponse($callback)),
        'broadcast' => $request->asBroadcast(new Channel('chat'), $callback),
    };

    expect($received)->toBeInstanceOf(Collection::class);

    return $received;
}

/**
 * @param  array<int, Message>  $messages
 * @return Collection<int, Message>
 */
function persistedMessages(array $messages): Collection
{
    return new Collection(unserialize(serialize($messages)));
}

/**
 * @return array<int, array<string, mixed>>
 */
function recordedRequestBodies(): array
{
    return Http::recorded()
        ->map(fn (array $pair): array => json_decode((string) $pair[0]->body(), true))
        ->values()
        ->all();
}

function claudeSignature(string $tag): string
{
    return 'CAQSsAwKEAgSGAI4AUIIdGhpbmtpbmcSDEC3aFbcaEDDnbHjaRoM3uBYEn+6vZG4yW9+IjDhNlQkKRsVfph4yDMBsnorE6OFRX0Q4oiPVVeFoEawPE/XL9c02b9AVnRimvfAiOEqzQsn8Fr9ZVo6o+M3s33aWb969ar7PQfmZ08aVvaLc+'
        .$tag.'QxGvT5n8wQ==';
}

/**
 * @param  array<int, Message>  $messages
 * @return array<int, array<string, mixed>>
 */
function claudeContinuation(array $messages): array
{
    $payload = AnthropicText::buildHttpRequestPayload(Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withTools([streamedWeatherTool()])
        ->withMessages($messages)
        ->toRequest());

    return json_decode((string) json_encode($payload['messages']), true);
}

/**
 * @param  array<int, Message>  $messages
 * @return array<int, array<string, mixed>>
 */
function openAIContinuation(array $messages): array
{
    return json_decode((string) json_encode((new OpenAIMessageMap($messages, []))()), true);
}

/**
 * @param  array<int, StreamEvent>  $events
 * @return Collection<int, Message>
 */
function messagesFromEvents(array $events): Collection
{
    return StreamedMessages::fromEvents((function () use ($events) {
        yield from $events;
    })());
}

describe('continuing a streamed conversation', function (): void {
    it('replays every Claude thinking block, signature and tool result in order', function (string $transport): void {
        config()->set('prism.providers.anthropic.api_key', 'fake-key');
        Event::fake();
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-interleaved-thinking-tools');

        $prompt = new UserMessage('Which is warmer, London or Rome?');

        $events = eventsFromStreamCallback($transport, Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([streamedWeatherTool()])
            ->withMaxSteps(3)
            ->withMessages([$prompt]));

        $streamed = StreamedMessages::fromEvents($events);

        expect($streamed->map(fn (Message $message): string => $message::class)->all())->toBe([
            AssistantMessage::class,
            ToolResultMessage::class,
            AssistantMessage::class,
        ]);

        $loopMessages = recordedRequestBodies()[1]['messages'];
        $messages = claudeContinuation([
            $prompt,
            ...persistedMessages($streamed->all()),
            new UserMessage('And Paris?'),
        ]);

        expect($messages)->toHaveCount(5)
            ->and(array_slice($messages, 0, 3))->toEqual($loopMessages)
            ->and($messages[3])->toEqual(['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => claudeSignature('SC03')],
                ['type' => 'text', 'text' => 'Rome is warmer: 71.6°F versus 57.2°F.'],
            ]])
            ->and($messages[4])->toBe(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'And Paris?']]]);

        $signatures = collect($messages)
            ->flatMap(fn (array $message): array => is_array($message['content']) ? $message['content'] : [])
            ->where('type', 'thinking')
            ->pluck('signature')
            ->all();

        expect($signatures)->toBe([claudeSignature('SA01'), claudeSignature('SB02'), claudeSignature('SC03')]);

        $toolUseIds = array_column(array_filter($messages[1]['content'], fn (array $block): bool => $block['type'] === 'tool_use'), 'id');

        expect(array_column($messages[2]['content'], 'tool_use_id'))->toBe(array_values($toolUseIds))
            ->and($toolUseIds)->toBe(['toolu_01B1QKSKsPNbnNZUvseVk81J', 'toolu_01XtoBm8idPge1qEsWZCzQz8']);
    })->with(['event stream', 'data stream', 'broadcast']);

    it('replays GPT reasoning, commentary and function calls with their outputs in order', function (string $transport): void {
        config()->set('prism.providers.openai.api_key', 'sk-test');
        Event::fake();
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-stream-commentary-tools');

        $prompt = new UserMessage('Compare the weather in Detroit and Chicago.');

        $events = eventsFromStreamCallback($transport, Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withTools([streamedWeatherTool()])
            ->withMaxSteps(3)
            ->withMessages([$prompt]));

        $streamed = StreamedMessages::fromEvents($events);

        expect($streamed->map(fn (Message $message): string => $message::class)->all())->toBe([
            AssistantMessage::class,
            ToolResultMessage::class,
            AssistantMessage::class,
        ]);

        $loopInput = recordedRequestBodies()[1]['input'];
        $input = openAIContinuation([
            $prompt,
            ...persistedMessages($streamed->all()),
            new UserMessage('Which one should I visit?'),
        ]);

        expect($input)->toHaveCount(9)
            ->and(array_slice($input, 0, 7))->toEqual($loopInput)
            ->and(array_map(
                fn (array $item): string => ($item['type'] ?? 'message').':'.($item['id'] ?? $item['call_id'] ?? $item['role']),
                $input
            ))->toBe([
                'message:user',
                'reasoning:rs_0b3543181d883d0b016ad120dc11aa87d1a6c673200d2627b4',
                'message:msg_0b3543181d883d0b016ad120dcc40887d1a6c673200d2627b4',
                'function_call:fc_0b3543181d883d0b016ad120dda4ac87d1a2d719a9c8df03ef',
                'function_call:fc_0b3543181d883d0b016ad120dda4c087d1a3b750b07e508b32',
                'function_call_output:call_9XZrHVWeYdSdyLpGJczIok1d',
                'function_call_output:call_LELisaCXtPpRoKvbM4434ouZ',
                'message:msg_0b3543181d883d0b016ad120e1b2c387d1a6c673200d26aa01',
                'message:user',
            ])
            ->and($input[1]['encrypted_content'])->toBe('gAAAAABq0SEttkngvqISYuHm-DONE-complete')
            ->and($input[2]['phase'])->toBe('commentary')
            ->and($input[7]['phase'])->toBe('final_answer')
            ->and($input[7]['content'][0]['text'])->toBe('Detroit is 18°C and cloudy; Chicago is 18°C and cloudy too.');

        $callIds = array_column(array_filter($input, fn (array $item): bool => ($item['type'] ?? null) === 'function_call'), 'call_id');
        $outputIds = array_column(array_filter($input, fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'), 'call_id');

        expect($outputIds)->toBe($callIds);
    })->with(['event stream', 'data stream', 'broadcast']);

    it('leaves the tool call of a refused Claude turn out of the continuation', function (): void {
        config()->set('prism.providers.anthropic.api_key', 'fake-key');
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-refusal-after-tool-use');

        $prompt = new UserMessage('Weather in Paris?');

        $events = eventsFromStreamCallback('event stream', Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([streamedWeatherTool()])
            ->withMaxSteps(3)
            ->withMessages([$prompt]));

        $streamed = StreamedMessages::fromEvents($events);

        expect($events->whereInstanceOf(ToolCallEvent::class))->toHaveCount(1)
            ->and($streamed)->toHaveCount(1)
            ->and($streamed[0]->toolCalls)->toBe([]);

        expect(claudeContinuation([$prompt, ...$streamed, new UserMessage('Tell me a joke instead.')]))->toBe([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Weather in Paris?']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Let me look that up.']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Tell me a joke instead.']]],
        ]);
    });

    it('leaves the function call of a refused GPT turn out of the continuation', function (): void {
        config()->set('prism.providers.openai.api_key', 'sk-test');
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-stream-refusal');

        $prompt = new UserMessage('Something the model refuses');

        $events = eventsFromStreamCallback('event stream', Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withTools([streamedWeatherTool()])
            ->withMaxSteps(3)
            ->withMessages([$prompt]));

        $streamed = StreamedMessages::fromEvents($events);
        $input = openAIContinuation([$prompt, ...$streamed, new UserMessage('Never mind.')]);

        expect($events->whereInstanceOf(ToolCallEvent::class))->not->toBeEmpty()
            ->and($streamed->whereInstanceOf(AssistantMessage::class)->flatMap->toolCalls)->toBeEmpty()
            ->and(array_column($input, 'type'))->not->toContain('function_call')
            ->and($input[0]['role'])->toBe('user')
            ->and(end($input)['role'])->toBe('user');
    });
});

describe('step events', function (): void {
    it('attaches each step finish additionalContent to that step assistant message', function (): void {
        $toolCall = new ToolCall('tool-1', 'search', ['q' => 'test']);
        $toolResult = new ToolResult('tool-1', 'search', ['q' => 'test'], 'found');
        $stepOne = ['content_blocks' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1']], 'stop_reason' => 'tool_use'];
        $stepTwo = ['content_blocks' => [['type' => 'text', 'text' => 'Done']], 'stop_reason' => 'end_turn'];

        $messages = messagesFromEvents([
            new StepStartEvent('evt-1', 1640995200),
            new TextStartEvent('evt-2', 1640995201, 'msg-1'),
            new TextDeltaEvent('evt-3', 1640995202, 'Searching', 'msg-1'),
            new ToolCallEvent('evt-4', 1640995203, $toolCall, 'msg-1'),
            new ToolResultEvent('evt-5', 1640995204, $toolResult, 'msg-1', true),
            new StepFinishEvent('evt-6', 1640995205, null, $stepOne, FinishReason::ToolCalls),
            new StepStartEvent('evt-7', 1640995206),
            new TextStartEvent('evt-8', 1640995207, 'msg-2'),
            new TextDeltaEvent('evt-9', 1640995208, 'Done', 'msg-2'),
            new StepFinishEvent('evt-10', 1640995209, null, $stepTwo, FinishReason::Stop),
            new StreamEndEvent('evt-11', 1640995210, FinishReason::Stop),
        ]);

        expect($messages)->toHaveCount(3);
        expect($messages[0])->toBeInstanceOf(AssistantMessage::class)
            ->content->toBe('Searching')
            ->additionalContent->toBe($stepOne);
        expect($messages[0]->toolCalls)->toBe([$toolCall]);
        expect($messages[1])->toBeInstanceOf(ToolResultMessage::class);
        expect($messages[1]->toolResults)->toBe([$toolResult]);
        expect($messages[2])->toBeInstanceOf(AssistantMessage::class)
            ->content->toBe('Done')
            ->additionalContent->toBe($stepTwo);
    });

    it('keeps one assistant message per step when a step has several text blocks', function (): void {
        $toolCall = new ToolCall('tool-1', 'search', ['q' => 'test']);

        $messages = messagesFromEvents([
            new StepStartEvent('evt-1', 1640995200),
            new TextStartEvent('evt-2', 1640995201, 'msg-1'),
            new TextDeltaEvent('evt-3', 1640995202, 'First. ', 'msg-1'),
            new ToolCallEvent('evt-4', 1640995203, $toolCall, 'msg-1'),
            new TextStartEvent('evt-5', 1640995204, 'msg-1'),
            new TextDeltaEvent('evt-6', 1640995205, 'Second.', 'msg-1'),
            new StepFinishEvent('evt-7', 1640995206, null, ['content_blocks' => [['type' => 'text', 'text' => 'First. ']]]),
            new StreamEndEvent('evt-8', 1640995207, FinishReason::ToolCalls),
        ]);

        expect($messages)->toHaveCount(1);
        expect($messages[0])
            ->content->toBe('First. Second.')
            ->toolCalls->toBe([$toolCall]);
    });

    it('keeps a step that only produced replay content, such as thinking', function (): void {
        $additionalContent = ['content_blocks' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig']], 'stop_reason' => 'max_tokens'];

        $messages = messagesFromEvents([
            new StepStartEvent('evt-1', 1640995200),
            new StepFinishEvent('evt-2', 1640995201, null, $additionalContent),
            new StreamEndEvent('evt-3', 1640995202, FinishReason::Length),
        ]);

        expect($messages)->toHaveCount(1);
        expect($messages[0])
            ->content->toBe('')
            ->additionalContent->toBe($additionalContent);
    });

    it('does not create a message for an empty step without replay content', function (): void {
        $messages = messagesFromEvents([
            new StepStartEvent('evt-1', 1640995200),
            new StepFinishEvent('evt-2', 1640995201, null, ['stop_reason' => 'refusal']),
            new StreamEndEvent('evt-3', 1640995202, FinishReason::Refusal),
        ]);

        expect($messages)->toBeEmpty();
    });

    it('leaves tool calls out of a step that finished without executing them', function (?FinishReason $finishReason, int $expectedToolCalls): void {
        $messages = messagesFromEvents([
            new StepStartEvent('evt-1', 1640995200),
            new TextStartEvent('evt-2', 1640995201, 'msg-1'),
            new TextDeltaEvent('evt-3', 1640995202, 'Searching', 'msg-1'),
            new ToolCallEvent('evt-4', 1640995203, new ToolCall('tool-1', 'search', ['q' => 'test']), 'msg-1'),
            new StepFinishEvent('evt-5', 1640995204, null, [], $finishReason),
            new StreamEndEvent('evt-6', 1640995205, $finishReason ?? FinishReason::Stop),
        ]);

        expect($messages)->toHaveCount(1);
        expect($messages[0])->content->toBe('Searching');
        expect($messages[0]->toolCalls)->toHaveCount($expectedToolCalls);
    })->with([
        'refusal' => [FinishReason::Refusal, 0],
        'length' => [FinishReason::Length, 0],
        'error' => [FinishReason::Error, 0],
        'tool calls' => [FinishReason::ToolCalls, 1],
        'not reported' => [null, 1],
    ]);

    it('keeps replay metadata out of the serialized step finish event', function (): void {
        $event = new StepFinishEvent('evt-1', 1640995200, null, ['content_blocks' => [['type' => 'text', 'text' => 'x']]]);

        expect($event->toArray())->not->toHaveKey('additionalContent')
            ->and(json_encode($event->toArray()))->not->toContain('content_blocks');
    });
});

describe('streams without step events', function (): void {
    it('accumulates text from multiple text delta events', function (): void {
        $messages = messagesFromEvents([
            new StreamStartEvent('evt-0', 1640995199, 'gpt-4', 'openai'),
            new TextStartEvent('evt-1', 1640995200, 'msg-1'),
            new TextDeltaEvent('evt-2', 1640995201, 'Hello', 'msg-1'),
            new TextDeltaEvent('evt-3', 1640995202, ' ', 'msg-1'),
            new TextDeltaEvent('evt-4', 1640995203, 'World', 'msg-1'),
            new StreamEndEvent('evt-5', 1640995204, FinishReason::Stop),
        ]);

        expect($messages)->toBeInstanceOf(Collection::class)->toHaveCount(1);
        expect($messages->first())->toBeInstanceOf(AssistantMessage::class)
            ->content->toBe('Hello World')
            ->toolCalls->toBeEmpty();
    });

    it('collects tool calls from tool call events', function (): void {
        $toolCall1 = new ToolCall('tool-1', 'search', ['q' => 'test']);
        $toolCall2 = new ToolCall('tool-2', 'calculate', ['x' => 5, 'y' => 10]);

        $messages = messagesFromEvents([
            new TextStartEvent('evt-1', 1640995200, 'msg-1'),
            new TextDeltaEvent('evt-2', 1640995201, 'Let me help', 'msg-1'),
            new ToolCallEvent('evt-3', 1640995202, $toolCall1, 'msg-1'),
            new ToolCallEvent('evt-4', 1640995203, $toolCall2, 'msg-1'),
            new StreamEndEvent('evt-5', 1640995204, FinishReason::Stop),
        ]);

        expect($messages)->toHaveCount(1);
        expect($messages->first())->toBeInstanceOf(AssistantMessage::class)
            ->content->toBe('Let me help')
            ->toolCalls->toBe([$toolCall1, $toolCall2]);
    });

    it('collects tool results from tool result events', function (): void {
        $toolCall = new ToolCall('tool-1', 'search', ['q' => 'test']);
        $toolResult = new ToolResult('tool-1', 'search', ['q' => 'test'], ['result' => 'found']);

        $messages = messagesFromEvents([
            new TextStartEvent('evt-1', 1640995200, 'msg-1'),
            new ToolCallEvent('evt-2', 1640995201, $toolCall, 'msg-1'),
            new StreamEndEvent('evt-3', 1640995202, FinishReason::ToolCalls),
            new ToolResultEvent('evt-4', 1640995203, $toolResult, 'msg-2', true),
            new StreamEndEvent('evt-5', 1640995204, FinishReason::Stop),
        ]);

        expect($messages)->toHaveCount(2);
        expect($messages[0])->toBeInstanceOf(AssistantMessage::class);
        expect($messages[0]->toolCalls)->toBe([$toolCall]);
        expect($messages[1])->toBeInstanceOf(ToolResultMessage::class);
        expect($messages[1]->toolResults)->toBe([$toolResult]);
    });

    it('starts a new assistant message for each text block', function (): void {
        $toolCall1 = new ToolCall('tool-1', 'search', ['q' => 'Laravel']);
        $toolResult1 = new ToolResult('tool-1', 'search', ['q' => 'Laravel'], ['result' => 'PHP Framework']);
        $toolCall2 = new ToolCall('tool-2', 'calculate', ['x' => 5]);
        $toolResult2 = new ToolResult('tool-2', 'calculate', ['x' => 5], ['result' => 10]);

        $messages = messagesFromEvents([
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
        ]);

        expect($messages)->toHaveCount(5);
        expect($messages[0])->toBeInstanceOf(AssistantMessage::class)->content->toBe('First response');
        expect($messages[0]->toolCalls)->toBe([$toolCall1]);
        expect($messages[1])->toBeInstanceOf(ToolResultMessage::class);
        expect($messages[1]->toolResults)->toBe([$toolResult1]);
        expect($messages[2])->toBeInstanceOf(AssistantMessage::class)->content->toBe('Second response');
        expect($messages[2]->toolCalls)->toBe([$toolCall2]);
        expect($messages[3])->toBeInstanceOf(ToolResultMessage::class);
        expect($messages[3]->toolResults)->toBe([$toolResult2]);
        expect($messages[4])->toBeInstanceOf(AssistantMessage::class)->content->toBe('Final response');
        expect($messages[4]->toolCalls)->toBeEmpty();
    });

    it('keeps text ahead of the first provider tool call', function (): void {
        $messages = messagesFromEvents([
            new TextStartEvent('evt-1', 1640995200, 'msg-1'),
            new TextDeltaEvent('evt-2', 1640995201, 'Searching the web.', 'msg-1'),
            new ProviderToolEvent('evt-3', 1640995202, 'web_search_call', 'in_progress', 'ws-1', []),
            new ProviderToolEvent('evt-4', 1640995203, 'web_search_call', 'completed', 'ws-1', []),
            new TextDeltaEvent('evt-5', 1640995204, 'Here is what I found.', 'msg-1'),
            new ProviderToolEvent('evt-6', 1640995205, 'web_search_call', 'in_progress', 'ws-2', []),
            new TextDeltaEvent('evt-7', 1640995206, ' And more.', 'msg-1'),
            new StreamEndEvent('evt-8', 1640995207, FinishReason::Stop),
        ]);

        expect($messages->pluck('content')->all())->toBe(['Searching the web.', 'Here is what I found. And more.']);
    });

    it('groups multiple tool results into one message', function (): void {
        $toolResult1 = new ToolResult('tool-1', 'search', ['q' => 'test1'], ['result' => 'found1']);
        $toolResult2 = new ToolResult('tool-2', 'search', ['q' => 'test2'], ['result' => 'found2']);

        $messages = messagesFromEvents([
            new ToolResultEvent('evt-1', 1640995200, $toolResult1, 'msg-1', true),
            new ToolResultEvent('evt-2', 1640995201, $toolResult2, 'msg-1', true),
            new StreamEndEvent('evt-3', 1640995202, FinishReason::Stop),
        ]);

        expect($messages)->toHaveCount(1);
        expect($messages->first())->toBeInstanceOf(ToolResultMessage::class)
            ->toolResults->toBe([$toolResult1, $toolResult2]);
    });
});

describe('edge cases', function (): void {
    it('returns no messages for an empty stream or one with only a stream end event', function (array $events): void {
        expect(StreamedMessages::fromEvents($events))->toBeInstanceOf(Collection::class)->toBeEmpty();
    })->with([
        'empty' => [[]],
        'stream end only' => [[new StreamEndEvent('evt-1', 1640995200, FinishReason::Stop)]],
    ]);

    it('accepts an array or a collection of events', function (): void {
        $events = [
            new TextStartEvent('evt-1', 1640995200, 'msg-1'),
            new TextDeltaEvent('evt-2', 1640995201, 'Hello', 'msg-1'),
            new StreamEndEvent('evt-3', 1640995202, FinishReason::Stop),
        ];

        expect(StreamedMessages::fromEvents($events)->sole()->content)->toBe('Hello')
            ->and(StreamedMessages::fromEvents(collect($events))->sole()->content)->toBe('Hello');
    });

    it('keeps what an interrupted stream produced, minus tool calls that never got a result', function (): void {
        $answered = new ToolCall('tool-1', 'search', ['q' => 'one']);
        $unanswered = new ToolCall('tool-2', 'search', ['q' => 'two']);
        $result = new ToolResult('tool-1', 'search', ['q' => 'one'], 'found');

        $messages = messagesFromEvents([
            new StepStartEvent('evt-1', 1640995200),
            new TextStartEvent('evt-2', 1640995201, 'msg-1'),
            new TextDeltaEvent('evt-3', 1640995202, 'Searching', 'msg-1'),
            new ToolCallEvent('evt-4', 1640995203, $answered, 'msg-1'),
            new ToolCallEvent('evt-5', 1640995204, $unanswered, 'msg-1'),
            new ToolResultEvent('evt-6', 1640995205, $result, 'msg-1', true),
        ]);

        expect($messages)->toHaveCount(2);
        expect($messages[0])->toBeInstanceOf(AssistantMessage::class)
            ->content->toBe('Searching')
            ->toolCalls->toBe([$answered]);
        expect($messages[1])->toBeInstanceOf(ToolResultMessage::class)
            ->toolResults->toBe([$result]);
    });
});
