<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\ProviderTool;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', 'fake-key');
});

function pauseTurnSchema(): ObjectSchema
{
    return new ObjectSchema('city_population', 'Population of a city', [
        new StringSchema('city', 'City name'),
        new NumberSchema('population', 'Population'),
    ], ['city', 'population']);
}

function pauseTurnWebSearch(): ProviderTool
{
    return new ProviderTool(type: 'web_search_20250305', name: 'web_search');
}

function pauseTurnLookupTool(int &$calls): \Prism\Prism\Tool
{
    return Tool::as('lookup_population')
        ->for('Look up a city population in the local database')
        ->withStringParameter('city', 'City name')
        ->using(function (string $city) use (&$calls): string {
            $calls++;

            return '545000';
        });
}

/**
 * @return array<int, array<string, mixed>>
 */
function pauseTurnRequestBodies(): array
{
    return Http::recorded()
        ->map(fn (array $pair): array => json_decode((string) $pair[0]->body(), true))
        ->values()
        ->all();
}

/**
 * @return array<int, array<string, mixed>>
 */
function pauseTurnFixtureContent(string $fixture): array
{
    return json_decode((string) file_get_contents(__DIR__."/../../Fixtures/anthropic/{$fixture}.json"), true)['content'];
}

/**
 * @return array<int, array<string, mixed>>
 */
function pauseTurnStreamedBlocks(): array
{
    return [
        ['type' => 'text', 'text' => 'Let me look that up.'],
        ['type' => 'server_tool_use', 'id' => 'srvtoolu_01StreamLisbonA', 'name' => 'web_search', 'input' => ['query' => 'Lisbon population 2026']],
        ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_01StreamLisbonA', 'content' => [[
            'type' => 'web_search_result',
            'title' => 'Lisbon - Population',
            'url' => 'https://www.example.com/lisbon-population',
            'encrypted_content' => 'EncryptedLisbonResultA',
            'page_age' => '3 days ago',
        ]]],
        ['type' => 'server_tool_use', 'id' => 'srvtoolu_01StreamLisbonB', 'name' => 'web_search', 'input' => ['query' => 'Lisbon municipality census 2026']],
    ];
}

/**
 * A paused turn carrying a client `tool_use` block, which Anthropic never
 * sends with `pause_turn`; it must not be executed or replayed.
 *
 * @return array<int, array<string, mixed>>
 */
function pauseTurnContentWithStrayToolUse(): array
{
    return [
        ...array_slice(pauseTurnFixtureContent('structured-pause-turn-1'), 0, 2),
        ['type' => 'tool_use', 'id' => 'toolu_01StrayLookup', 'name' => 'lookup_population', 'input' => ['city' => 'Lisbon']],
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $content
 * @return array<string, mixed>
 */
function pauseTurnMessage(array $content, string $stopReason): array
{
    return [
        'id' => 'msg_01PauseTurn'.ucfirst($stopReason),
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5-5',
        'content' => $content,
        'stop_reason' => $stopReason,
        'stop_sequence' => null,
        'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $content
 */
function pauseTurnSse(array $content, string $stopReason): string
{
    $events = [['type' => 'message_start', 'message' => [...pauseTurnMessage([], $stopReason), 'stop_reason' => null]]];

    foreach ($content as $index => $block) {
        $input = $block['input'] ?? null;
        $start = $input === null ? $block : [...$block, 'input' => new \stdClass];
        $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => $start];

        if ($input !== null) {
            $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($input)]];
        }

        $events[] = ['type' => 'content_block_stop', 'index' => $index];
    }

    $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason, 'stop_sequence' => null], 'usage' => ['output_tokens' => 20]];
    $events[] = ['type' => 'message_stop'];

    return implode('', array_map(
        fn (array $event): string => "event: {$event['type']}\ndata: ".json_encode($event)."\n\n",
        $events
    ));
}

describe('structured', function (): void {
    it('resumes a paused turn and takes the structured output from the final step', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-pause-turn');

        $response = Prism::structured()
            ->using('anthropic', 'claude-opus-5-5')
            ->withSchema(pauseTurnSchema())
            ->withProviderTools([pauseTurnWebSearch()])
            ->withMaxSteps(3)
            ->withPrompt('What is the population of Lisbon?')
            ->asStructured();

        $bodies = pauseTurnRequestBodies();

        expect($bodies)->toHaveCount(2);
        expect($bodies[1]['messages'])->toHaveCount(2);
        expect($bodies[1]['messages'][0])->toEqual($bodies[0]['messages'][0]);
        expect($bodies[1]['messages'][1])->toEqual([
            'role' => 'assistant',
            'content' => pauseTurnFixtureContent('structured-pause-turn-1'),
        ]);
        expect($bodies[1]['output_config']['format']['type'])->toBe('json_schema');

        expect($response->steps)->toHaveCount(2);
        expect($response->steps[0]->finishReason)->toBe(FinishReason::Pause);
        expect($response->steps[0]->structured)->toBe([]);
        expect($response->finishReason)->toBe(FinishReason::Stop);
        expect($response->structured)->toBe(['city' => 'Lisbon', 'population' => 545000]);
        expect($response->usage->promptTokens)->toBe(410 + 780);
    });

    it('resumes a paused turn in tool calling mode without re-sending the instruction or running client tools', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-tool-mode-pause-turn');

        $calls = 0;

        $response = Prism::structured()
            ->using('anthropic', 'claude-opus-5-5')
            ->withSchema(pauseTurnSchema())
            ->withTools([pauseTurnLookupTool($calls)])
            ->withProviderTools([pauseTurnWebSearch()])
            ->withMaxSteps(3)
            ->withPrompt('What is the population of Lisbon?')
            ->withProviderOptions(['use_tool_calling' => true])
            ->asStructured();

        $bodies = pauseTurnRequestBodies();
        $instructions = array_filter(
            $bodies[1]['messages'],
            fn (array $message): bool => $message['role'] === 'user'
                && str_contains((string) json_encode($message['content']), 'Please use the output_structured_data tool')
        );

        expect($bodies)->toHaveCount(2);
        expect(array_keys($instructions))->toBe([1]);
        expect(array_slice($bodies[1]['messages'], 0, count($bodies[0]['messages'])))->toEqual($bodies[0]['messages']);
        expect(Arr::last($bodies[1]['messages']))->toEqual([
            'role' => 'assistant',
            'content' => pauseTurnFixtureContent('structured-tool-mode-pause-turn-1'),
        ]);
        expect($calls)->toBe(0);
        expect($response->steps)->toHaveCount(2);
        expect($response->steps[0]->finishReason)->toBe(FinishReason::Pause);
        expect($response->structured)->toBe(['city' => 'Lisbon', 'population' => 545000]);
    });

    it('returns the pause when maxSteps runs out', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-pause-turn');

        $response = Prism::structured()
            ->using('anthropic', 'claude-opus-5-5')
            ->withSchema(pauseTurnSchema())
            ->withProviderTools([pauseTurnWebSearch()])
            ->withMaxSteps(1)
            ->withPrompt('What is the population of Lisbon?')
            ->asStructured();

        expect(pauseTurnRequestBodies())->toHaveCount(1);
        expect($response->steps)->toHaveCount(1);
        expect($response->finishReason)->toBe(FinishReason::Pause);
        expect($response->structured)->toBe([]);
        expect(Arr::last($response->steps[0]->messages)->additionalContent['content_blocks'])
            ->toEqual(pauseTurnFixtureContent('structured-pause-turn-1'));
    });

    it('never runs client tool calls from a paused turn', function (): void {
        Http::fake(['*' => Http::sequence()
            ->push(pauseTurnMessage(pauseTurnContentWithStrayToolUse(), 'pause_turn'))
            ->push(pauseTurnMessage([['type' => 'text', 'text' => '{"city":"Lisbon","population":545000}']], 'end_turn')),
        ]);

        $calls = 0;

        $response = Prism::structured()
            ->using('anthropic', 'claude-opus-5-5')
            ->withSchema(pauseTurnSchema())
            ->withTools([pauseTurnLookupTool($calls)])
            ->withProviderTools([pauseTurnWebSearch()])
            ->withMaxSteps(3)
            ->withPrompt('What is the population of Lisbon?')
            ->asStructured();

        $bodies = pauseTurnRequestBodies();

        expect($calls)->toBe(0);
        expect($response->steps[0]->toolCalls)->toBe([]);
        expect($response->structured)->toBe(['city' => 'Lisbon', 'population' => 545000]);
        expect(Arr::last($bodies[1]['messages'])['content'])->toEqual(array_slice(pauseTurnContentWithStrayToolUse(), 0, 2));
    });
});

describe('text', function (): void {
    it('never runs client tool calls from a paused turn', function (): void {
        Http::fake(['*' => Http::sequence()
            ->push(pauseTurnMessage(pauseTurnContentWithStrayToolUse(), 'pause_turn'))
            ->push(pauseTurnMessage([['type' => 'text', 'text' => 'Lisbon has about 545,000 residents.']], 'end_turn')),
        ]);

        $calls = 0;

        $response = Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([pauseTurnLookupTool($calls)])
            ->withProviderTools([pauseTurnWebSearch()])
            ->withMaxSteps(3)
            ->withPrompt('What is the population of Lisbon?')
            ->asText();

        $bodies = pauseTurnRequestBodies();

        expect($calls)->toBe(0);
        expect($response->steps)->toHaveCount(2);
        expect($response->steps[0]->finishReason)->toBe(FinishReason::Pause);
        expect($response->steps[0]->toolCalls)->toBe([]);
        expect($response->finishReason)->toBe(FinishReason::Stop);
        expect($bodies[1]['messages'][1])->toEqual([
            'role' => 'assistant',
            'content' => array_slice(pauseTurnContentWithStrayToolUse(), 0, 2),
        ]);
    });
});

describe('stream', function (): void {
    it('resumes a paused turn within one stream', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-pause-turn');

        $events = collect(iterator_to_array(Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withProviderTools([pauseTurnWebSearch()])
            ->withMaxSteps(3)
            ->withPrompt('What is the population of Lisbon?')
            ->asStream(), false));

        $stepFinishes = $events->whereInstanceOf(StepFinishEvent::class)->values();
        $end = $events->last();

        expect($events->whereInstanceOf(StreamStartEvent::class))->toHaveCount(1);
        expect($events->whereInstanceOf(StepStartEvent::class))->toHaveCount(2);
        expect($events->whereInstanceOf(StreamEndEvent::class))->toHaveCount(1);
        expect($stepFinishes->map(fn (StepFinishEvent $event): ?FinishReason => $event->finishReason)->all())
            ->toBe([FinishReason::Pause, FinishReason::Stop]);
        expect($stepFinishes[0]->additionalContent)
            ->content_blocks->toEqual(pauseTurnStreamedBlocks())
            ->stop_reason->toBe('pause_turn');

        expect($end)->toBeInstanceOf(StreamEndEvent::class);
        expect($end->finishReason)->toBe(FinishReason::Stop);
        expect($end->usage->promptTokens)->toBe(410 + 780);
        expect($end->usage->completionTokens)->toBe(96 + 40);
        expect($events->whereInstanceOf(TextDeltaEvent::class)->map(fn (TextDeltaEvent $event): string => $event->delta)->implode(''))
            ->toBe('Let me look that up.Lisbon has about 545,000 residents.');
    });

    it('sends the paused assistant content back unchanged', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-pause-turn');

        iterator_to_array(Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withProviderTools([pauseTurnWebSearch()])
            ->withMaxSteps(3)
            ->withPrompt('What is the population of Lisbon?')
            ->asStream(), false);

        $bodies = pauseTurnRequestBodies();

        expect($bodies)->toHaveCount(2);
        expect($bodies[1]['messages'])->toHaveCount(2);
        expect($bodies[1]['messages'][0])->toEqual($bodies[0]['messages'][0]);
        expect($bodies[1]['messages'][1])->toEqual(['role' => 'assistant', 'content' => pauseTurnStreamedBlocks()]);
    });

    it('ends with the pause when maxSteps runs out', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-pause-turn');

        $events = collect(iterator_to_array(Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withProviderTools([pauseTurnWebSearch()])
            ->withMaxSteps(1)
            ->withPrompt('What is the population of Lisbon?')
            ->asStream(), false));

        $stepFinishes = $events->whereInstanceOf(StepFinishEvent::class)->values();

        expect(pauseTurnRequestBodies())->toHaveCount(1);
        expect($stepFinishes)->toHaveCount(1);
        expect($stepFinishes[0]->finishReason)->toBe(FinishReason::Pause);
        expect($events->whereInstanceOf(StreamEndEvent::class))->toHaveCount(1);
        expect($events->last())->toBeInstanceOf(StreamEndEvent::class);
        expect($events->last()->finishReason)->toBe(FinishReason::Pause);
        expect($events->last()->additionalContent['stop_reason'])->toBe('pause_turn');
    });

    it('never runs client tool calls from a paused turn', function (): void {
        $headers = ['Content-Type' => 'text/event-stream'];

        Http::fake(['*' => Http::sequence()
            ->push(pauseTurnSse(pauseTurnContentWithStrayToolUse(), 'pause_turn'), 200, $headers)
            ->push(pauseTurnSse([['type' => 'text', 'text' => 'Lisbon has about 545,000 residents.']], 'end_turn'), 200, $headers),
        ]);

        $calls = 0;

        $events = collect(iterator_to_array(Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([pauseTurnLookupTool($calls)])
            ->withProviderTools([pauseTurnWebSearch()])
            ->withMaxSteps(3)
            ->withPrompt('What is the population of Lisbon?')
            ->asStream(), false));

        $bodies = pauseTurnRequestBodies();

        expect($calls)->toBe(0);
        expect($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty();
        expect($events->last()->finishReason)->toBe(FinishReason::Stop);
        expect($bodies[1]['messages'][1])->toEqual([
            'role' => 'assistant',
            'content' => array_slice(pauseTurnContentWithStrayToolUse(), 0, 2),
        ]);
    });
});
