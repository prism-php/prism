<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\StreamCollector;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Structured\ResponseBuilder as StructuredBuilder;
use Prism\Prism\Structured\Step as StructuredStep;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

dataset('cache write aggregates', [
    'two complete steps' => [[[248, 148, 100], [200, 120, 80]], 448, 268, 180],
    'nonzero unsplit step' => [[[248, 148, 100], [100, null, null]], 348, null, null],
    'zero write step' => [[[248, 148, 100], [0, null, null]], 248, 148, 100],
    'null write step' => [[[248, 148, 100], [null, null, null]], 248, 148, 100],
    'only zero writes' => [[[0, null, null]], 0, 0, 0],
    'no write reports' => [[[null, null, null]], null, null, null],
    'independently nullable split' => [[[10, 10, null]], 10, 10, null],
]);

it('preserves complete or unknown lifetime totals in both response builders', function (array $reports, ?int $total, ?int $five, ?int $hour, string $mode): void {
    $builder = $mode === 'text' ? new ResponseBuilder : new StructuredBuilder;
    foreach ($reports as [$writes, $fiveMinutes, $oneHour]) {
        $usage = new Usage(11, 23, $writes, cacheWrite5mInputTokens: $fiveMinutes, cacheWrite1hInputTokens: $oneHour);
        $step = $mode === 'text'
            ? new Step('{}', FinishReason::Stop, [], [], [], $usage, new Meta('', ''), [], [])
            : new StructuredStep('{}', FinishReason::Stop, $usage, new Meta('', ''), [], []);
        $builder->addStep($step);
    }
    $usage = $builder->toResponse()->usage;
    expect($usage->cacheWriteInputTokens)->toBe($total)
        ->and($usage->cacheWrite5mInputTokens)->toBe($five)
        ->and($usage->cacheWrite1hInputTokens)->toBe($hour);
    if ($five !== null && $hour !== null) {
        expect($usage->cacheWriteInputTokens)->toBe($five + $hour);
    }
})->with('cache write aggregates')->with(['text', 'structured']);

it('retains lifetime completeness in stream state and the stream collector', function (array $reports, ?int $total, ?int $five, ?int $hour): void {
    $state = new StreamState;
    $usages = [];
    foreach ($reports as [$writes, $fiveMinutes, $oneHour]) {
        $usages[] = $usage = new Usage(11, 23, $writes, cacheWrite5mInputTokens: $fiveMinutes, cacheWrite1hInputTokens: $oneHour);
        $state->addUsage($usage);
    }
    $result = null;
    $stream = (function () use ($usages): Generator {
        foreach ($usages as $usage) {
            yield new StepFinishEvent('owned', 0, $usage);
        }
        yield new StreamEndEvent('end', 0, FinishReason::Stop);
    })();
    $collector = new StreamCollector($stream, onCompleteCallback: function ($request, $messages, Response $response) use (&$result): void {
        $result = $response->usage;
    });
    iterator_to_array($collector->collect());
    foreach ([$state->usage(), $result] as $usage) {
        expect($usage->cacheWriteInputTokens)->toBe($total)
            ->and($usage->cacheWrite5mInputTokens)->toBe($five)
            ->and($usage->cacheWrite1hInputTokens)->toBe($hour);
        if ($five !== null && $hour !== null) {
            expect($usage->cacheWriteInputTokens)->toBe($five + $hour);
        }
    }
})->with('cache write aggregates');
