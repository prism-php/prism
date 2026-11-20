<?php

declare(strict_types=1);

namespace Tests\Streaming;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;

dataset('tool identity providers', [
    'Azure' => [Provider::Azure], 'DeepSeek' => [Provider::DeepSeek], 'Groq' => [Provider::Groq],
    'Mistral' => [Provider::Mistral], 'OpenAI chat' => [Provider::OpenAI], 'OpenRouter' => [Provider::OpenRouter],
    'Qwen' => [Provider::Qwen], 'Requesty' => [Provider::Requesty], 'XAI' => [Provider::XAI], 'Z' => [Provider::Z],
]);

dataset('tool identity streams', fn (): array => array_map(fn (array $fixture): array => [$fixture], json_decode(
    file_get_contents(__DIR__.'/../Fixtures/streaming/tool-call-identities.json'), true, flags: JSON_THROW_ON_ERROR)));

/** Fixture envelopes follow the native API shape; parsing and execution stay real. */
function identityStream(Provider $provider, array $deltas): string
{
    $frames = [];
    foreach ($deltas as $index => $calls) {
        $finished = $index === array_key_last($deltas);
        $frames[] = $provider === Provider::Qwen
            ? ['output' => ['choices' => [['message' => ['role' => 'assistant', 'tool_calls' => $calls], 'finish_reason' => $finished ? 'tool_calls' : 'null']]]]
            : ['choices' => [['index' => 0, 'delta' => ['tool_calls' => $calls], 'finish_reason' => null]]];
    }
    if ($provider !== Provider::Qwen) {
        $frames[] = ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]];
    }

    return implode('', array_map(fn (array $frame): string => 'data: '.json_encode($frame, JSON_THROW_ON_ERROR)."\n\n", $frames))
        .($provider === Provider::Qwen ? '' : "data: [DONE]\n\n");
}

it('keeps one non-empty tool identity through the real streamed execution and wire round-trip', function (Provider $provider, array $fixture): void {
    config(['prism.providers.'.$provider->value.'.api_key' => 'owned-stream-fixture',
        'prism.providers.'.$provider->value.'.url' => 'https://stream.fixture.invalid',
        'prism.providers.azure.api_version' => '2024-10-21', 'prism.providers.openai.api_format' => 'chat_completions']);
    $final = $provider === Provider::Qwen
        ? ['output' => ['choices' => [['message' => ['role' => 'assistant', 'content' => 'Owned done.'], 'finish_reason' => 'null']]]]
        : ['choices' => [['index' => 0, 'delta' => ['content' => 'Owned done.'], 'finish_reason' => null]]];
    $finish = 'data: '.json_encode($final, JSON_THROW_ON_ERROR)."\n\n";
    $finish .= $provider === Provider::Qwen
        ? 'data: '.json_encode(['output' => ['choices' => [['message' => ['content' => ''], 'finish_reason' => 'stop']]]], JSON_THROW_ON_ERROR)."\n\n"
        : 'data: '.json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]], JSON_THROW_ON_ERROR)."\n\ndata: [DONE]\n\n";
    Http::fake(['*' => Http::sequence()
        ->push(identityStream($provider, $fixture['deltas']), 200, ['Content-Type' => 'text/event-stream'])
        ->push($finish, 200, ['Content-Type' => 'text/event-stream'])])
        ->preventStrayRequests();
    $invoked = [];
    $tool = Tool::as('weather')->for('Owned weather fixture')->withStringParameter('city', 'City')
        ->using(function (string $city) use (&$invoked): string {
            $invoked[] = $city;

            return 'weather:'.$city;
        });
    $events = iterator_to_array(Prism::text()->using($provider, 'fixture-model')->withPrompt('Owned question')
        ->withTools([$tool])->withMaxSteps(2)->asStream(), false);
    $calls = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolCallEvent));
    $results = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolResultEvent));
    expect($calls)->toHaveCount(count($fixture['expected']))->and($results)->toHaveCount(count($calls));
    expect($invoked)->toBe(array_column($fixture['expected'], 'city'));
    $ids = [];
    foreach ($calls as $index => $call) {
        $expected = $fixture['expected'][$index];
        expect($call->toolCall->id)->toBeString()->not->toBe('');
        if ($expected['id'] !== null) {
            expect($call->toolCall->id)->toBe($expected['id']);
        } elseif ($provider !== Provider::Mistral) {
            expect($call->toolCall->id)->toStartWith($provider->value.'_');
        }
        expect($results[$index]->toolResult->toolCallId)->toBe($call->toolCall->id)
            ->and($results[$index]->toolResult->result)->toBe('weather:'.$expected['city']);
        $ids[] = $call->toolCall->id;
    }
    expect(array_unique($ids))->toHaveCount(count($ids));
    $requests = Http::recorded();
    expect($requests)->toHaveCount(2);
    $wire = $requests[1][0]->data();
    $messages = $provider === Provider::Qwen ? $wire['input']['messages'] : $wire['messages'];
    $assistant = collect($messages)->firstWhere('role', 'assistant');
    expect(array_is_list($assistant['tool_calls']))->toBeTrue();
    expect(array_column($assistant['tool_calls'], 'id'))->toBe($ids);
    $toolMessages = array_values(array_filter($messages, fn (array $message): bool => $message['role'] === 'tool'));
    expect(array_column($toolMessages, 'tool_call_id'))->toBe($ids);
    if ($provider === Provider::Mistral) {
        foreach ($fixture['expected'] as $index => $expected) {
            if ($expected['id'] === null) {
                expect($toolMessages[$index]['tool_call_id'])->toMatch('/^[a-zA-Z0-9]{9}$/D');
            }
        }
    }
    expect(end($events))->toBeInstanceOf(StreamEndEvent::class)
        ->and(end($events)->finishReason)->toBe(FinishReason::Stop);
})->with('tool identity providers')->with('tool identity streams');
