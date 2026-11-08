<?php

declare(strict_types=1);

namespace Tests\Streaming;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;

/** Native Responses fixture: the item id is not its provider call_id. */
function responsesIdentityStream(array $calls): string
{
    $frames = [['type' => 'response.created', 'response' => ['id' => 'resp_owned', 'model' => 'fixture-model']]];
    foreach ($calls as $call) {
        $item = [
            'type' => 'function_call', 'id' => $call['id'], 'call_id' => $call['call_id'], 'name' => 'weather', 'arguments' => '',
        ];
        if ($call['omit_call_id'] ?? false) {
            unset($item['call_id']);
        }
        if ($call['omit_item_id'] ?? false) {
            unset($item['id']);
        }
        $frames[] = ['type' => 'response.output_item.added', 'output_index' => $call['index'], 'item' => $item];
    }
    $output = [];
    foreach (array_reverse($calls) as $call) {
        $wireId = $call['later_id'] ?? $call['id'];
        $arguments = json_encode(['city' => $call['city']], JSON_THROW_ON_ERROR);
        $delta = ['type' => 'response.function_call_arguments.delta', 'output_index' => $call['index'], 'item_id' => $wireId, 'delta' => $arguments];
        $done = ['type' => 'response.function_call_arguments.done', 'output_index' => $call['index'], 'item_id' => $wireId, 'arguments' => $arguments];
        if ($call['omit_delta_index'] ?? false) {
            unset($delta['output_index'], $done['output_index']);
        }
        $frames[] = $delta;
        $frames[] = $done;
        $item = ['type' => 'function_call', 'id' => $wireId, 'call_id' => $call['call_id'], 'name' => 'weather', 'arguments' => $arguments, 'status' => 'completed'];
        if ($call['omit_call_id'] ?? false) {
            unset($item['call_id']);
        }
        if ($call['omit_item_id'] ?? false) {
            unset($item['id']);
        }
        $frames[] = ['type' => 'response.output_item.done', 'output_index' => $call['index'], 'item' => $item];
        $output[] = $item;
    }
    $frames[] = ['type' => 'response.completed', 'response' => ['id' => 'resp_owned', 'status' => 'completed', 'output' => $output,
        'usage' => ['input_tokens' => 10, 'output_tokens' => 8]]];

    return implode('', array_map(fn (array $frame): string => 'data: '.json_encode($frame, JSON_THROW_ON_ERROR)."\n\n", $frames));
}

function fakeResponsesIdentity(array $calls): void
{
    config(['prism.providers.openai.api_key' => 'owned-stream-fixture', 'prism.providers.openai.api_format' => 'responses',
        'prism.providers.openai.url' => 'https://stream.fixture.invalid']);
    $message = ['type' => 'message', 'id' => 'msg_owned', 'role' => 'assistant', 'status' => 'completed',
        'content' => [['type' => 'output_text', 'text' => 'Owned done.', 'annotations' => []]]];
    $finish = [
        ['type' => 'response.created', 'response' => ['id' => 'resp_final', 'model' => 'fixture-model']],
        ['type' => 'response.output_text.delta', 'item_id' => 'msg_owned', 'output_index' => 0, 'content_index' => 0, 'delta' => 'Owned done.'],
        ['type' => 'response.output_text.done', 'item_id' => 'msg_owned', 'output_index' => 0, 'content_index' => 0, 'text' => 'Owned done.'],
        ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $message],
        ['type' => 'response.completed', 'response' => ['id' => 'resp_final', 'status' => 'completed', 'output' => [$message],
            'usage' => ['input_tokens' => 15, 'output_tokens' => 9]]],
    ];
    Http::fake(['*' => Http::sequence()->push(responsesIdentityStream($calls), 200, ['Content-Type' => 'text/event-stream'])
        ->push(implode('', array_map(fn (array $frame): string => 'data: '.json_encode($frame, JSON_THROW_ON_ERROR)."\n\n", $finish)), 200, ['Content-Type' => 'text/event-stream'])])
        ->preventStrayRequests();
}

it('keeps Responses item identity stable and distinct from the real provider result key', function (array $calls): void {
    fakeResponsesIdentity($calls);
    $invoked = [];
    $tool = Tool::as('weather')->for('Owned fixture')->withStringParameter('city', 'City')
        ->using(function (string $city) use (&$invoked): string {
            $invoked[] = $city;

            return 'weather:'.$city;
        });
    $events = iterator_to_array(Prism::text()->using(Provider::OpenAI, 'fixture-model')->withPrompt('Owned question')
        ->withTools([$tool])->withMaxSteps(2)->asStream(), false);
    $callEvents = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolCallEvent));
    $deltaEvents = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolCallDeltaEvent));
    $resultEvents = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolResultEvent));
    expect($callEvents)->toHaveCount(count($calls))->and($deltaEvents)->toHaveCount(count($calls))
        ->and($resultEvents)->toHaveCount(count($calls));
    $identities = [];
    foreach ($callEvents as $event) {
        $toolCall = $event->toolCall;
        $original = collect($calls)->firstWhere('call_id', $toolCall->resultId);
        expect($original)->not->toBeNull();
        expect($toolCall->id)->toBeString()->not->toBe('')->not->toBe($toolCall->resultId);
        if ($original['id'] === null || $original['id'] === '') {
            expect($toolCall->id)->toStartWith('openai_');
        } else {
            expect($toolCall->id)->toBe($original['id']);
        }
        expect(collect($deltaEvents)->where('toolId', $toolCall->id))->toHaveCount(1);
        $result = collect($resultEvents)->first(fn ($result): bool => $result->toolResult->toolCallResultId === $toolCall->resultId);
        expect($result->toolResult->toolCallId)->toBe($toolCall->id)
            ->and($result->toolResult->result)->toBe('weather:'.$original['city']);
        $identities[$toolCall->resultId] = $toolCall->id;
    }
    expect(array_unique($identities))->toHaveCount(count($calls));
    expect($invoked)->toHaveCount(count($calls));
    $requests = Http::recorded();
    expect($requests)->toHaveCount(2);
    $wire = $requests[1][0]->data()['input'];
    foreach ($calls as $original) {
        $call = collect($wire)->first(fn ($item): bool => ($item['type'] ?? null) === 'function_call' && $item['call_id'] === $original['call_id']);
        $result = collect($wire)->first(fn ($item): bool => ($item['type'] ?? null) === 'function_call_output' && $item['call_id'] === $original['call_id']);
        expect($call['call_id'])->toBe($original['call_id'])->and($result['output'])->toBe('weather:'.$original['city']);
        $providerId = $original['id'] === null || $original['id'] === '' ? ($original['later_id'] ?? null) : $original['id'];
        if ($providerId === null) {
            expect(array_key_exists('id', $call))->toBeFalse();
        } else {
            expect($call['id'])->toBe($providerId);
        }
    }
    expect(end($events))->toBeInstanceOf(StreamEndEvent::class)->and(end($events)->finishReason)->toBe(FinishReason::Stop);
})->with([
    'null item id' => [[['index' => 4, 'id' => null, 'call_id' => 'call_real', 'city' => 'Paris']]],
    'empty item id' => [[['index' => 4, 'id' => '', 'call_id' => 'call_real', 'city' => 'Paris']]],
    'absent item id' => [[['index' => 4, 'id' => null, 'omit_item_id' => true, 'call_id' => 'call_real', 'city' => 'Paris']]],
    'late item id' => [[['index' => 4, 'id' => null, 'later_id' => 'fc_later', 'call_id' => 'call_real', 'city' => 'Paris']]],
    'distinct provider ids' => [[['index' => 4, 'id' => 'fc_original', 'call_id' => 'call_real', 'city' => 'Paris']]],
    'first non-null provider item id' => [[['index' => 4, 'id' => 'fc_original', 'later_id' => 'fc_conflict', 'call_id' => 'call_real', 'city' => 'Paris']]],
    'provider item correlation without optional delta index' => [[['index' => 4, 'id' => 'fc_original', 'omit_delta_index' => true, 'call_id' => 'call_real', 'city' => 'Paris']]],
    'parallel missing item ids' => [[['index' => 4, 'id' => null, 'call_id' => 'call_paris', 'city' => 'Paris'],
        ['index' => 7, 'id' => null, 'call_id' => 'call_oslo', 'city' => 'Oslo']]],
]);

it('refuses a Responses function call without a real non-empty provider result key before executing any tool', function (mixed $callId, bool $omit): void {
    fakeResponsesIdentity([['index' => 4, 'id' => 'fc_original', 'call_id' => $callId, 'omit_call_id' => $omit, 'city' => 'Paris']]);
    $invoked = 0;
    $tool = Tool::as('weather')->for('Owned fixture')->withStringParameter('city', 'City')
        ->using(function () use (&$invoked): string {
            $invoked++;

            return 'Must not run';
        });
    $this->expectException(PrismException::class);
    $this->expectExceptionMessage('OpenAI function call at output index 4 requires a non-empty provider call_id.');
    try {
        iterator_to_array(Prism::text()->using(Provider::OpenAI, 'fixture-model')->withPrompt('Owned question')
            ->withTools([$tool])->withMaxSteps(2)->asStream(), false);
    } finally {
        expect($invoked)->toBe(0);
        Http::assertSentCount(1);
    }
})->with(['null call_id' => [null, false], 'empty call_id' => ['', false], 'non-string call_id' => [123, false], 'absent call_id' => [null, true]]);
