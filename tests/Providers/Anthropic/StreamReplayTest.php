<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Providers\Anthropic\Handlers\Text;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Streaming\StreamedMessages;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', 'fake-key');
});

/**
 * @param  array<string, int>  $calls
 */
function anthropicStreamWeatherTool(array &$calls): \Prism\Prism\Tool
{
    return Tool::as('get_weather')
        ->for('Get current weather for a city')
        ->withStringParameter('city', 'The city')
        ->using(function (string $city) use (&$calls): string {
            $calls[$city] = ($calls[$city] ?? 0) + 1;

            return match ($city) {
                'London' => '14°C',
                'Rome' => '22°C',
                default => '20°C',
            };
        });
}

/**
 * @return array<int, array<string, mixed>>
 */
function anthropicStreamRequestBodies(): array
{
    return Http::recorded()
        ->map(fn (array $pair): array => json_decode((string) $pair[0]->body(), true))
        ->values()
        ->all();
}

function anthropicStreamSignature(string $tag): string
{
    return 'CAQSsAwKEAgSGAI4AUIIdGhpbmtpbmcSDEC3aFbcaEDDnbHjaRoM3uBYEn+6vZG4yW9+IjDhNlQkKRsVfph4yDMBsnorE6OFRX0Q4oiPVVeFoEawPE/XL9c02b9AVnRimvfAiOEqzQsn8Fr9ZVo6o+M3s33aWb969ar7PQfmZ08aVvaLc+'
        .$tag.'QxGvT5n8wQ==';
}

/**
 * @return array<int, array<string, mixed>>
 */
function anthropicInterleavedStreamBlocks(): array
{
    return [
        ['type' => 'thinking', 'thinking' => "NOLDNO unscrambles to LONDON. City B is Rome.\n\n", 'signature' => anthropicStreamSignature('SA01')],
        ['type' => 'text', 'text' => "I'll check London first, then Rome."],
        ['type' => 'tool_use', 'id' => 'toolu_01B1QKSKsPNbnNZUvseVk81J', 'name' => 'get_weather', 'input' => ['city' => 'London'], 'caller' => ['type' => 'direct']],
        ['type' => 'thinking', 'thinking' => '', 'signature' => anthropicStreamSignature('SB02')],
        ['type' => 'tool_use', 'id' => 'toolu_01XtoBm8idPge1qEsWZCzQz8', 'name' => 'get_weather', 'input' => ['city' => 'Rome'], 'caller' => ['type' => 'direct']],
    ];
}

describe('ordered replay', function (): void {
    it('replays each streamed thinking block with its own signature, in stream order', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-interleaved-thinking-tools');

        $calls = [];

        $events = collect(iterator_to_array(Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicStreamWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Which is warmer, London or Rome?')
            ->asStream(), false));

        $bodies = anthropicStreamRequestBodies();

        expect($bodies)->toHaveCount(2);
        expect($bodies[1]['messages'][1])->toEqual(['role' => 'assistant', 'content' => anthropicInterleavedStreamBlocks()]);
        expect($calls)->toBe(['London' => 1, 'Rome' => 1]);

        $stepFinishes = $events->whereInstanceOf(StepFinishEvent::class)->values();

        expect($stepFinishes)->toHaveCount(2);
        expect($stepFinishes[0]->additionalContent)
            ->content_blocks->toEqual(anthropicInterleavedStreamBlocks())
            ->thinking_blocks->toEqual([anthropicInterleavedStreamBlocks()[0], anthropicInterleavedStreamBlocks()[3]])
            ->thinking->toBe("NOLDNO unscrambles to LONDON. City B is Rome.\n\n")
            ->thinking_signature->toBe(anthropicStreamSignature('SA01'))
            ->stop_reason->toBe('tool_use');
        expect($stepFinishes[1]->additionalContent)
            ->thinking->toBe('')
            ->thinking_signature->toBe(anthropicStreamSignature('SC03'))
            ->stop_reason->toBe('end_turn');
    });

    it('keeps replay blobs off the stream end event but carries the final step metadata', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-interleaved-thinking-tools');

        $calls = [];

        $end = collect(iterator_to_array(Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicStreamWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Which is warmer, London or Rome?')
            ->asStream(), false))->last();

        expect($end)->toBeInstanceOf(StreamEndEvent::class);
        expect($end->finishReason)->toBe(FinishReason::Stop);
        expect($end->additionalContent)->toBe([
            'thinking' => '',
            'thinking_signature' => anthropicStreamSignature('SC03'),
            'stop_reason' => 'end_turn',
        ]);
    });

    it('reconciles usage from message_delta per step and sums steps once', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-interleaved-thinking-tools');

        $calls = [];

        $events = collect(iterator_to_array(Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicStreamWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Which is warmer, London or Rome?')
            ->asStream(), false));

        $stepUsages = $events->whereInstanceOf(StepFinishEvent::class)->map(fn (StepFinishEvent $event): ?\Prism\Prism\ValueObjects\Usage => $event->usage)->values();

        expect($stepUsages[0])
            ->promptTokens->toBe(481)
            ->completionTokens->toBe(862)
            ->cacheWriteInputTokens->toBe(120)
            ->cacheReadInputTokens->toBe(0)
            ->thoughtTokens->toBe(717);
        expect($stepUsages[1])
            ->promptTokens->toBe(3)
            ->completionTokens->toBe(140)
            ->cacheWriteInputTokens->toBe(90)
            ->cacheReadInputTokens->toBe(1300)
            ->thoughtTokens->toBe(60);
        expect($events->last()->usage)
            ->promptTokens->toBe(484)
            ->completionTokens->toBe(1002)
            ->cacheWriteInputTokens->toBe(210)
            ->cacheReadInputTokens->toBe(1300)
            ->thoughtTokens->toBe(777);
    });
});

/**
 * Streams a turn and rebuilds its messages from the events the way a caller
 * persisting the conversation would, and returns the persisted (serialized
 * and rebuilt) messages.
 *
 * @param  array<string, int>  $calls
 * @return array<int, Message>
 */
function anthropicPersistedStreamTurn(string $prompt, array &$calls): array
{
    $pendingRequest = Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withTools([anthropicStreamWeatherTool($calls)])
        ->withMaxSteps(3)
        ->withPrompt($prompt);

    $events = iterator_to_array($pendingRequest->asStream(), false);

    return unserialize(serialize(StreamedMessages::fromEvents($events)->all()));
}

/**
 * The messages of the request Prism would send to continue a conversation.
 *
 * @param  array<int, Message>  $messages
 * @return array<int, array<string, mixed>>
 */
function anthropicContinuationMessages(array $messages): array
{
    $calls = [];

    $payload = Text::buildHttpRequestPayload(Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withTools([anthropicStreamWeatherTool($calls)])
        ->withMessages($messages)
        ->toRequest());

    return json_decode((string) json_encode($payload['messages']), true);
}

/**
 * `tool_use` blocks without a `tool_result` in the next message, or whose
 * input is not a JSON object.
 *
 * @param  array<int, array<string, mixed>>  $messages
 * @return array<int, string>
 */
function anthropicInvalidToolUse(array $messages): array
{
    $invalid = [];

    foreach ($messages as $position => $message) {
        foreach (is_array($message['content']) ? $message['content'] : [] as $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            $resultIds = array_column(is_array($messages[$position + 1]['content'] ?? null) ? $messages[$position + 1]['content'] : [], 'tool_use_id');

            if (! in_array($block['id'], $resultIds, true) || ! is_array($block['input'])) {
                $invalid[] = $block['id'];
            }
        }
    }

    return $invalid;
}

describe('tool execution gating', function (): void {
    it('does not execute a complete tool call when the turn ends in a refusal', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-refusal-after-tool-use');

        $calls = [];

        $events = collect(iterator_to_array(Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicStreamWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Weather in Paris?')
            ->asStream(), false));

        expect($calls)->toBe([]);
        expect(Http::recorded())->toHaveCount(1);
        expect($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty();
        expect($events->whereInstanceOf(StreamEndEvent::class))->toHaveCount(1);
        expect($events->last()->finishReason)->toBe(FinishReason::Refusal);
        expect($events->last()->additionalContent['stop_details'])->toBe([
            'type' => 'refusal',
            'category' => 'cyber',
            'explanation' => 'This request was declined because it could enable cyber harm.',
        ]);
        expect($events->whereInstanceOf(StepFinishEvent::class)->sole()->additionalContent)
            ->stop_reason->toBe('refusal')
            ->stop_details->category->toBe('cyber');
    });

    it('does not execute tool calls when the turn is truncated by max_tokens', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-max-tokens-after-tool-use');

        $calls = [];

        $events = collect(iterator_to_array(Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicStreamWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Weather in Paris and London?')
            ->asStream(), false));

        expect($calls)->toBe([]);
        expect(Http::recorded())->toHaveCount(1);
        expect($events->whereInstanceOf(ToolCallEvent::class))->toHaveCount(1);
        expect($events->whereInstanceOf(StreamEndEvent::class))->toHaveCount(1);
        expect($events->last()->finishReason)->toBe(FinishReason::Length);
    });

    it('persists a refused turn without the tool call it never executed', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-refusal-after-tool-use');

        $calls = [];
        $persisted = anthropicPersistedStreamTurn('Weather in Paris?', $calls);

        expect($persisted)->toHaveCount(1);
        expect($persisted[0])->toBeInstanceOf(AssistantMessage::class);
        expect($persisted[0]->toolCalls)->toBe([]);
        expect($persisted[0]->additionalContent['content_blocks'])->toBe([
            ['type' => 'text', 'text' => 'Let me look that up.'],
        ]);

        $messages = anthropicContinuationMessages([
            new UserMessage('Weather in Paris?'),
            ...$persisted,
            new UserMessage('Then just tell me a joke.'),
        ]);

        expect($calls)->toBe([]);
        expect(anthropicInvalidToolUse($messages))->toBe([]);
        expect($messages)->toBe([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Weather in Paris?']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Let me look that up.']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Then just tell me a joke.']]],
        ]);
    });

    it('persists a max_tokens turn without its complete or truncated tool calls', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-max-tokens-after-tool-use');

        $calls = [];
        $persisted = anthropicPersistedStreamTurn('Weather in Paris and London?', $calls);

        expect($persisted)->toHaveCount(1);
        expect($persisted[0]->toolCalls)->toBe([]);
        expect($persisted[0]->additionalContent['content_blocks'])->toBe([
            ['type' => 'text', 'text' => 'Checking both cities.'],
        ]);

        $messages = anthropicContinuationMessages([
            new UserMessage('Weather in Paris and London?'),
            ...$persisted,
            new UserMessage('Just Paris, please.'),
        ]);

        expect($calls)->toBe([]);
        expect(anthropicInvalidToolUse($messages))->toBe([]);
        expect($messages[1])->toBe(['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Checking both cities.']]]);
    });

    it('keeps every tool call of a turn that ended in tool_use, each answered on replay', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-interleaved-thinking-tools');

        $calls = [];
        $persisted = anthropicPersistedStreamTurn('Which is warmer, London or Rome?', $calls);

        $messages = anthropicContinuationMessages([
            new UserMessage('Which is warmer, London or Rome?'),
            ...$persisted,
            new UserMessage('And Paris?'),
        ]);

        expect($calls)->toBe(['London' => 1, 'Rome' => 1]);
        expect($persisted[0]->toolCalls)->toHaveCount(2);
        expect(array_column($messages[1]['content'], 'type'))->toContain('tool_use');
        expect(anthropicInvalidToolUse($messages))->toBe([]);
    });

    it('only executes tool calls made by the serving model after a mid-output fallback', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-fallback-mid-output');

        $calls = [];

        $events = collect(iterator_to_array(Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicStreamWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Weather in Berlin or Paris?')
            ->withProviderOptions(['fallbacks' => 'default'])
            ->asStream(), false));

        expect($calls)->toBe(['Paris' => 1]);

        $replayed = anthropicStreamRequestBodies()[1]['messages'][1]['content'];

        expect(array_map(fn (array $block): string => $block['type'], $replayed))
            ->toBe(['text', 'fallback', 'thinking', 'text', 'tool_use']);
        expect($replayed[4]['id'])->toBe('toolu_01StreamFallbackServed1');
        expect($replayed[2]['signature'])->toBe(anthropicStreamSignature('SF02'));

        $firstStep = $events->whereInstanceOf(StepFinishEvent::class)->first();

        expect($firstStep->additionalContent['fallbacks'])->toBe([['from' => 'claude-opus-5-5', 'to' => 'claude-opus-4-8']]);
        expect($firstStep->additionalContent['usage_iterations'])->toHaveCount(2);
        expect($firstStep->additionalContent['thinking_signature'])->toBe(anthropicStreamSignature('SF02'));
        expect($firstStep->usage)->promptTokens->toBe(450)->completionTokens->toBe(90);
        expect($events->last()->additionalContent['usage_iterations'])->toHaveCount(1);
    });
});

it('collects streamed steps into messages that replay exactly what the provider loop sent', function (): void {
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-interleaved-thinking-tools');

    $calls = [];

    $stream = Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withTools([anthropicStreamWeatherTool($calls)])
        ->withMaxSteps(3)
        ->withPrompt('Which is warmer, London or Rome?')
        ->asStream();

    $collected = StreamedMessages::fromEvents(iterator_to_array($stream, false));

    expect($collected)->toHaveCount(3);
    expect($collected[0])->toBeInstanceOf(AssistantMessage::class);
    expect($collected[1])->toBeInstanceOf(ToolResultMessage::class);
    expect($collected[2])->toBeInstanceOf(AssistantMessage::class);
    expect($collected[0]->content)->toBe("I'll check London first, then Rome.");
    expect($collected[0]->toolCalls)->toHaveCount(2);

    $persisted = unserialize(serialize([new UserMessage('Which is warmer, London or Rome?'), ...$collected->all()]));
    $replayed = json_decode((string) json_encode(MessageMap::map($persisted)), true);
    $loopMessages = anthropicStreamRequestBodies()[1]['messages'];

    expect(array_slice($replayed, 0, 3))->toEqual($loopMessages);
    expect($replayed[3]['content'])->toEqual([
        ['type' => 'thinking', 'thinking' => '', 'signature' => anthropicStreamSignature('SC03')],
        ['type' => 'text', 'text' => 'Rome is warmer: 71.6°F versus 57.2°F.'],
    ]);
});
