<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-test-1234'));
    config()->set('prism.providers.openai.api_format', 'chat_completions');
});

describe('Streaming for OpenAI chat/completions', function (): void {
    it('can generate text with a basic stream', function (): void {
        FixtureResponse::fakeStreamResponses('chat/completions', 'openai-chat-completions/stream-basic-text');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4o-mini')
            ->withPrompt('Who are you?')
            ->asStream();

        $text = '';
        $events = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }
        }

        expect($events)
            ->not->toBeEmpty()
            ->and($text)->not->toBeEmpty()
            ->and($text)->toContain("Hello! I'm an AI assistant. How can I help?");

        $lastEvent = end($events);
        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
    });

    it('stream end event has non-null usage', function (): void {
        FixtureResponse::fakeStreamResponses('chat/completions', 'openai-chat-completions/stream-basic-text');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4o-mini')
            ->withPrompt('Who are you?')
            ->asStream();

        $lastEvent = null;
        foreach ($response as $event) {
            $lastEvent = $event;
        }

        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->usage)->not->toBeNull();
        expect($lastEvent->usage->promptTokens)->toBe(10);
        expect($lastEvent->usage->completionTokens)->toBe(13);
    });
});

/**
 * @param  array<string, mixed>  ...$chunks
 */
function chatCompletionsSse(array ...$chunks): string
{
    return collect($chunks)
        ->map(fn (array $chunk): string => 'data: '.json_encode($chunk)."\n\n")
        ->implode('')."data: [DONE]\n\n";
}

/**
 * @param  array<string, mixed>  $delta
 * @return array<string, mixed>
 */
function chatCompletionsChunk(array $delta, ?string $finishReason = null): array
{
    return [
        'id' => 'chatcmpl-finish',
        'model' => 'gpt-4o-mini',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
    ];
}

/**
 * @return array<string, mixed>
 */
function chatCompletionsToolDelta(string $arguments, bool $opening): array
{
    $call = ['index' => 0, 'function' => ['arguments' => $arguments]];

    if ($opening) {
        $call = ['index' => 0, 'id' => 'call_finish', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => $arguments]];
    }

    return ['tool_calls' => [$call]];
}

describe('tool calls that arrive in the finish chunk', function (): void {
    it('runs the tool when the last fragment and the tool_calls finish share a chunk', function (array $chunks): void {
        Http::fake([
            '*' => Http::sequence()
                ->push(chatCompletionsSse(...$chunks), 200, ['Content-Type' => 'text/event-stream'])
                ->push(chatCompletionsSse(
                    chatCompletionsChunk(['content' => 'Sunny.']),
                    chatCompletionsChunk([], 'stop'),
                ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $cities = [];
        $events = collect(iterator_to_array(Prism::text()
            ->using(Provider::OpenAI, 'gpt-4o-mini')
            ->withTools([
                Tool::as('get_weather')
                    ->for('Get the weather for a city')
                    ->withStringParameter('city', 'The city')
                    ->using(function (string $city) use (&$cities): string {
                        $cities[] = $city;

                        return "Sunny in {$city}";
                    }),
            ])
            ->withMaxSteps(3)
            ->withPrompt('What is the weather in Detroit?')
            ->asStream(), false));

        expect($events->whereInstanceOf(ToolCallEvent::class))->toHaveCount(1);

        $call = $events->whereInstanceOf(ToolCallEvent::class)->sole()->toolCall;
        $replayed = collect(Http::recorded()[1][0]->data()['messages'])->firstWhere('role', 'assistant');

        expect($call->id)->toBe('call_finish')
            ->and($call->name)->toBe('get_weather')
            ->and($call->arguments())->toBe(['city' => 'Detroit'])
            ->and($cities)->toBe(['Detroit'])
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
            ->and($events->whereInstanceOf(StepFinishEvent::class))->toHaveCount(2)
            ->and($events->last())->toBeInstanceOf(StreamEndEvent::class)
            ->and($events->last()->finishReason)->toBe(FinishReason::Stop)
            ->and($replayed['tool_calls'])->toHaveCount(1);

        Http::assertSentCount(2);
    })->with([
        'the whole call in the finish chunk' => [[
            chatCompletionsChunk(chatCompletionsToolDelta('{"city":"Detroit"}', true), 'tool_calls'),
        ]],
        'the last fragment in the finish chunk' => [[
            chatCompletionsChunk(chatCompletionsToolDelta('{"city":', true)),
            chatCompletionsChunk(chatCompletionsToolDelta('"Detroit"}', false), 'tool_calls'),
        ]],
        'the finish on a later chunk' => [[
            chatCompletionsChunk(chatCompletionsToolDelta('{"city":"Detroit"}', true)),
            chatCompletionsChunk([], 'tool_calls'),
        ]],
    ]);

    it('ends a client-executed call in the finish chunk with ToolCalls, not Stop', function (): void {
        Http::fake([
            '*' => Http::response(chatCompletionsSse(
                chatCompletionsChunk(chatCompletionsToolDelta('{"city":"Detroit"}', true), 'tool_calls'),
            ), 200, ['Content-Type' => 'text/event-stream']),
        ])->preventStrayRequests();

        $events = collect(iterator_to_array(Prism::text()
            ->using(Provider::OpenAI, 'gpt-4o-mini')
            ->withTools([
                Tool::as('get_weather')
                    ->for('Get the weather for a city')
                    ->withStringParameter('city', 'The city')
                    ->clientExecuted(),
            ])
            ->withMaxSteps(3)
            ->withPrompt('What is the weather in Detroit?')
            ->asStream(), false));

        expect($events->whereInstanceOf(ToolCallEvent::class))->toHaveCount(1)
            ->and($events->last())->toBeInstanceOf(StreamEndEvent::class)
            ->and($events->last()->finishReason)->toBe(FinishReason::ToolCalls);

        Http::assertSentCount(1);
    });
});
