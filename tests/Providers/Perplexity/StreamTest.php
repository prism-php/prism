<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.perplexity.api_key', env('PERPLEXITY_API_KEY', 'pplx'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'perplexity/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Perplexity, 'sonar')
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

    expect($events)->not->toBeEmpty();

    /**
     * @var StreamEndEvent $lastEvent
     */
    $lastEvent = end($events);

    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class)
        ->and($lastEvent->usage)->not->toBeNull()
        ->and($lastEvent->usage->promptTokens)->toBe(4)
        ->and($lastEvent->usage->completionTokens)->toBe(97)
        ->and($lastEvent->finishReason)->toBe(FinishReason::Stop)
        ->and(collect($events)->whereInstanceOf(StreamEndEvent::class))->toHaveCount(1)
        ->and(collect($events)->whereInstanceOf(StepStartEvent::class))->toHaveCount(1)
        ->and(collect($events)->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
        ->and(collect($events)->whereInstanceOf(StepFinishEvent::class)->sole()->usage->toArray())->toBe($lastEvent->usage->toArray())
        ->and($text)->not->toBeEmpty();

    Http::assertSent(static function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true;
    });
});

function perplexityStreamSse(array ...$chunks): string
{
    return collect($chunks)
        ->map(fn (array $chunk): string => 'data: '.json_encode($chunk)."\n\n")
        ->implode('');
}

/**
 * @return array<string, mixed>
 */
function perplexityStreamChunk(string $content, ?string $finishReason = null, string $object = 'chat.completion.chunk'): array
{
    return [
        'id' => 'pplx-stream',
        'model' => 'sonar',
        'object' => $object,
        'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 9, 'total_tokens' => 13],
        'choices' => [array_filter([
            'index' => 0,
            'delta' => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => $finishReason,
        ], fn (mixed $value): bool => $value !== null)],
    ];
}

/**
 * @return Collection<int, StreamEvent>
 */
function perplexityStream(): Collection
{
    return collect(iterator_to_array(Prism::text()
        ->using(Provider::Perplexity, 'sonar')
        ->withPrompt('Who are you?')
        ->asStream(), false));
}

it('ends the stream once when more than one chunk carries a finish reason', function (): void {
    Http::fake([
        '*' => Http::response(perplexityStreamSse(
            perplexityStreamChunk('I am Sonar.'),
            perplexityStreamChunk('', 'stop'),
            perplexityStreamChunk('', 'stop', 'chat.completion.done'),
        ), 200, ['Content-Type' => 'text/event-stream']),
    ])->preventStrayRequests();

    $events = perplexityStream();

    expect($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
        ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
            'prompt_tokens' => 4,
            'completion_tokens' => 9,
            'cache_write_input_tokens' => null,
            'cache_read_input_tokens' => null,
            'thought_tokens' => null,
        ])
        ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
        ->and($events->whereInstanceOf(TextCompleteEvent::class))->toHaveCount(1)
        ->and($events->last())->toBeInstanceOf(StreamEndEvent::class);
});

it('ends with a step finish and Stop when the stream closes without a finish reason', function (): void {
    Http::fake([
        '*' => Http::response(perplexityStreamSse(
            perplexityStreamChunk('I am'),
            perplexityStreamChunk(' Sonar.'),
        ), 200, ['Content-Type' => 'text/event-stream']),
    ])->preventStrayRequests();

    $events = perplexityStream();

    expect($events->whereInstanceOf(TextDeltaEvent::class)->map->delta->implode(''))->toBe('I am Sonar.')
        ->and($events->whereInstanceOf(TextCompleteEvent::class))->toHaveCount(1)
        ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
        ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop)
        ->and($events->last())->toBeInstanceOf(StreamEndEvent::class);
});
