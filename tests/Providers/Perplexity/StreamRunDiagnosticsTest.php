<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRunException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;

// Synthetic fixtures following Perplexity's documented terminal event names
// and nested response snapshot shape; these are not live recordings.
it('throws typed diagnostics after provisional deltas on an unsuccessful stream', function (string $shape, string $status, bool $withError): void {
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', false);
    Event::fake([GenerationCompleted::class, GenerationFailed::class]);
    $sse = file_get_contents(__DIR__.'/../../Fixtures/perplexity/agent-stream-incomplete-'.$shape.'-1.sse');
    $sse = str_replace(['response.incomplete', '"status":"incomplete"'], ['response.'.$status, '"status":"'.$status.'"'], $sse);
    // A run error must not be diverted into the generic ErrorEvent path.
    if ($withError) {
        $sse = str_replace('"error":null', '"error":{"type":"run_error","message":"Run stopped"}', $sse);
    }
    Http::fake(['api.perplexity.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])])->preventStrayRequests();
    $events = [];

    try {
        foreach (Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asStream() as $event) {
            $events[] = $event;
        }
        test()->fail('An unsuccessful terminal event must throw.');
    } catch (PrismRunException $exception) {
        expect($exception->code())->toBe('run_'.$status)
            ->and($exception->status())->toBe($status)
            ->and($exception->runId())->toBe('resp_partial')
            ->and($exception->incompleteReason())->toBe('max_steps')
            ->and($exception->output()[0]['content'][0]['text'])->toBe('Private partial finding')
            ->and($exception->output()[1]['results'][0]['snippet'])->toBe('Private source')
            ->and($exception->citations())->toBe([['type' => 'url_citation', 'url' => 'https://example.test/source']])
            ->and($exception->usage()->promptTokens)->toBe(12)
            ->and($exception->usage()->completionTokens)->toBe(8)
            ->and($exception->usage()->cost)->toBe(0.01)
            ->and($exception->usageDetails()['input_tokens'])->toBe(12)
            ->and($exception->httpStatus)->toBe(200)
            ->and($exception->responseBody)->toBeNull()
            ->and(json_encode($exception))->not->toContain('Private partial finding');
    }

    expect(array_map(fn (StreamEvent $event): string => $event::class, $events))->toBe([
        StreamStartEvent::class, TextStartEvent::class, TextDeltaEvent::class,
    ]);
    expect($events[1]->messageId)->toBe($events[2]->messageId)
        ->and($events[2]->delta)->toBe('Private partial finding');
    Event::assertNotDispatched(GenerationCompleted::class);
    Event::assertDispatchedTimes(GenerationFailed::class, 1);
})->with(['nested', 'flat'])->with(['incomplete', 'failed', 'cancelled'])->with([false, true]);

it('cannot mistake an unsuccessful terminal type for success when snapshot status is missing or inconsistent', function (?string $status): void {
    $run = ['id' => 'resp_no_usage'];
    if ($status !== null) {
        $run['status'] = $status;
    }
    $sse = 'data: '.json_encode(['type' => 'response.incomplete', 'response' => $run])."\n\n";
    Http::fake(['api.perplexity.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])])->preventStrayRequests();
    $events = [];

    try {
        foreach (Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asStream() as $event) {
            $events[] = $event;
        }
        test()->fail('The terminal event itself reports an unsuccessful run.');
    } catch (PrismRunException $exception) {
        expect($exception->code())->toBe('run_incomplete')
            ->and($exception->runId())->toBe('resp_no_usage')
            ->and($exception->usage())->toBeNull()
            ->and($exception->output())->toBe([])
            ->and($exception->citations())->toBe([]);
    }
    expect(array_map(fn (StreamEvent $event): string => $event::class, $events))->toBe([StreamStartEvent::class]);
})->with([null, 'completed']);

it('ends a successful stream once with usage from its terminal snapshot', function (string $shape): void {
    $sse = file_get_contents(__DIR__.'/../../Fixtures/perplexity/agent-stream-incomplete-'.$shape.'-1.sse');
    $sse = str_replace(['response.incomplete', '"status":"incomplete"'], ['response.completed', '"status":"completed"'], $sse);
    Http::fake(['api.perplexity.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])])->preventStrayRequests();

    $events = iterator_to_array(Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asStream());

    expect(array_map(fn (StreamEvent $event): string => $event::class, $events))->toBe([
        StreamStartEvent::class, TextStartEvent::class, TextDeltaEvent::class, TextCompleteEvent::class, StreamEndEvent::class,
    ]);
    expect($events[4]->finishReason)->toBe(FinishReason::Stop)
        ->and($events[4]->usage->promptTokens)->toBe(12)
        ->and($events[4]->usage->completionTokens)->toBe(8)
        ->and($events[4]->usage->cost)->toBe(0.01);
})->with(['nested', 'flat']);

it('ends an unknown non-progress status with Unknown rather than dropping the ending', function (bool $nested): void {
    $run = ['id' => 'resp_expired', 'status' => 'expired', 'usage' => ['input_tokens' => 12, 'output_tokens' => 8]];
    $terminal = $nested ? ['type' => 'response.expired', 'response' => $run] : $run;
    $sse = 'data: '.json_encode(['status' => 'queued'])."\n\n"
        .'data: '.json_encode(['type' => 'response.output_text.delta', 'delta' => 'Partial'])."\n\n"
        .'data: '.json_encode($terminal)."\n\n"
        .'data: '.json_encode(['type' => 'response.output_text.delta', 'delta' => 'must not arrive'])."\n\n";
    Http::fake(['api.perplexity.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])])->preventStrayRequests();

    $events = iterator_to_array(Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asStream());
    expect(array_map(fn (StreamEvent $event): string => $event::class, $events))->toBe([
        StreamStartEvent::class, TextStartEvent::class, TextDeltaEvent::class, TextCompleteEvent::class, StreamEndEvent::class,
    ])->and($events[4]->finishReason)->toBe(FinishReason::Unknown)
        ->and($events[4]->usage->promptTokens)->toBe(12);
})->with([false, true]);

it('preserves rate-limit backoff classification even on a failed terminal snapshot', function (bool $nested, bool $outerError): void {
    $error = ['type' => 'rate_limit_exceeded', 'message' => 'Back off'];
    $run = ['id' => 'resp_limited', 'status' => 'failed'];
    if (! $outerError) {
        $run['error'] = $error;
    }
    $terminal = $nested ? ['type' => 'response.failed', 'response' => $run] : $run;
    if ($outerError) {
        $terminal['error'] = $error;
    }
    $sse = 'data: '.json_encode($terminal)."\n\n";
    Http::fake(['api.perplexity.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])])->preventStrayRequests();

    expect(fn (): array => iterator_to_array(Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asStream()))
        ->toThrow(PrismRateLimitedException::class);
})->with([false, true])->with([false, true]);
