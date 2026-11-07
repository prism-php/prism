<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Exceptions\PrismRefusalException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Tool;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'test-key');
    config()->set('prism.providers.openai.api_format', 'chat_completions');
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', false);
    Event::fake([GenerationCompleted::class, GenerationFailed::class]);
    Http::preventStrayRequests();
});

it('exposes refusals before interpreting content or the finish reason', function (string $mode, string $finishReason, string $refusal, ?string $content): void {
    Http::fake(['*chat/completions' => Http::response([
        'id' => 'chatcmpl-refused', 'model' => 'gpt-4o-mini',
        // Refusal is authoritative even alongside partial content.
        'choices' => [['message' => ['content' => $content, 'refusal' => $refusal], 'finish_reason' => $finishReason]],
    ])]);

    try {
        if ($mode === 'text') {
            Prism::text()->using('openai', 'gpt-4o-mini')->withPrompt('Test')->asText();
        } else {
            Prism::structured()->using('openai', 'gpt-4o-mini')->withPrompt('Test')
                ->withSchema(new ObjectSchema('answer', 'Answer', [new StringSchema('text', 'Text')], ['text']))
                ->asStructured();
        }
        test()->fail('A refusal must not return an answer');
    } catch (PrismRefusalException $exception) {
        expect($exception->code())->toBe('response_refused')
            ->and($exception->refusal())->toBe($refusal)
            ->and($exception->httpStatus)->toBe(200)
            ->and($exception->responseBody)->toBeNull()
            ->and($exception->getMessage())->not->toContain('sensitive refusal')
            ->and(json_encode($exception))->not->toContain('sensitive refusal');
    }
    Event::assertNotDispatched(GenerationCompleted::class);
})->with(['text', 'structured'])->with(['stop', 'length', 'content_filter', 'tool_calls'])->with(['sensitive refusal', '0'])->with([null, 'Partial content']);

it('preserves genuinely empty text with an absent null or empty refusal', function (?string $content, array $refusal): void {
    Http::fake(['*chat/completions' => Http::response([
        'id' => 'chatcmpl-empty', 'model' => 'gpt-4o-mini',
        'choices' => [['message' => ['content' => $content, ...$refusal], 'finish_reason' => 'stop']],
    ])]);

    $response = Prism::text()->using('openai', 'gpt-4o-mini')->withPrompt('Test')->asText();
    expect($response->text)->toBe('')->and($response->finishReason)->toBe(FinishReason::Stop);
})->with([null, ''])->with(['absent' => [[]], 'null' => [['refusal' => null]], 'empty' => [['refusal' => '']]]);

it('collects refusal deltas and throws before completing a refused stream', function (string $ending): void {
    $chunks = [
        ['choices' => [['delta' => ['content' => 'Provisional'], 'finish_reason' => null]]],
        ['choices' => [['delta' => ['refusal' => 'Cannot '], 'finish_reason' => null]]],
        ['choices' => [['delta' => ['refusal' => 'answer.'], 'finish_reason' => in_array($ending, ['done', 'eof'], true) ? null : $ending]]],
    ];
    $body = implode('', array_map(fn (array $chunk): string => 'data: '.json_encode($chunk)."\n\n", $chunks));
    Http::fake(['*chat/completions' => Http::response($body.($ending === 'eof' ? '' : "data: [DONE]\n\n"), 200, ['Content-Type' => 'text/event-stream'])]);

    $events = [];
    try {
        foreach (Prism::text()->using('openai', 'gpt-4o-mini')->withPrompt('Test')->asStream() as $event) {
            $events[] = $event;
        }
        test()->fail('A refused stream must throw');
    } catch (PrismRefusalException $exception) {
        expect($exception->code())->toBe('response_refused')->and($exception->refusal())->toBe('Cannot answer.');
    }
    expect(collect($events)->whereInstanceOf(TextDeltaEvent::class)->pluck('delta')->all())->toBe(['Provisional'])
        ->and(collect($events)->whereInstanceOf(StreamEndEvent::class))->toBeEmpty()
        ->and(collect($events)->whereInstanceOf(StepFinishEvent::class))->toBeEmpty()
        ->and(collect($events)->whereInstanceOf(TextCompleteEvent::class))->toBeEmpty();
    Event::assertNotDispatched(GenerationCompleted::class);
    Event::assertDispatchedTimes(GenerationFailed::class, 1);
})->with(['stop', 'length', 'tool_calls', 'done', 'eof']);

it('completes a genuinely empty stream with a null or empty refusal', function (?string $refusal): void {
    Http::fake(['*chat/completions' => Http::response(
        'data: '.json_encode(['choices' => [['delta' => ['content' => null, 'refusal' => $refusal], 'finish_reason' => 'stop']]])."\n\ndata: [DONE]\n\n",
        200, ['Content-Type' => 'text/event-stream']
    )]);
    $events = iterator_to_array(Prism::text()->using('openai', 'gpt-4o-mini')->withPrompt('Test')->asStream());
    expect(collect($events)->whereInstanceOf(TextDeltaEvent::class))->toBeEmpty()
        ->and(end($events))->toBeInstanceOf(StreamEndEvent::class)
        ->and(end($events)->finishReason)->toBe(FinishReason::Stop);
})->with([null, '']);

it('does not execute tools when their response is refused', function (bool $stream): void {
    $called = false;
    $tool = (new Tool)->as('lookup')->for('Look up data')->using(function () use (&$called): string {
        $called = true;

        return 'result';
    });
    $message = ['refusal' => 'Private refusal', 'tool_calls' => [
        ['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{}']],
    ]];
    $body = ['choices' => [[$stream ? 'delta' : 'message' => $message, 'finish_reason' => 'tool_calls']]];
    Http::fake(['*chat/completions' => Http::response($stream ? 'data: '.json_encode($body)."\n\ndata: [DONE]\n\n" : $body)]);
    $request = Prism::text()->using('openai', 'gpt-4o-mini')->withPrompt('Test')->withTools([$tool]);

    try {
        $stream ? iterator_to_array($request->asStream()) : $request->asText();
        test()->fail('A refused tool response must throw.');
    } catch (PrismRefusalException $exception) {
        expect($exception->refusal())->toBe('Private refusal');
    }
    expect($called)->toBeFalse();
    Event::assertNotDispatched(GenerationCompleted::class);
})->with([true, false]);
