<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Anthropic\Handlers\Batch\Results;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\ValueObjects\Usage;

dataset('reported cache write lifetimes', [
    'five minutes' => [148, 0],
    'one hour' => [0, 100],
    'both lifetimes' => [148, 100],
    'reported zero' => [0, 0],
    'unreported split' => [null, null],
]);

/** @return array<string, mixed> */
function cacheWriteUsage(?int $fiveMinutes, ?int $oneHour): array
{
    $usage = [
        'input_tokens' => 2048,
        'output_tokens' => 503,
        'cache_creation_input_tokens' => $fiveMinutes === null ? 248 : $fiveMinutes + $oneHour,
        'cache_read_input_tokens' => 1800,
    ];

    if ($fiveMinutes !== null) {
        $usage['cache_creation'] = [
            'ephemeral_5m_input_tokens' => $fiveMinutes,
            'ephemeral_1h_input_tokens' => $oneHour,
        ];
    }

    return $usage;
}

function assertCacheWriteUsage(Usage $usage, ?int $fiveMinutes, ?int $oneHour): void
{
    expect($usage->cacheWrite5mInputTokens)->toBe($fiveMinutes)
        ->and($usage->cacheWrite1hInputTokens)->toBe($oneHour)
        ->and($usage->cacheWriteInputTokens)->toBe($fiveMinutes === null ? 248 : $fiveMinutes + $oneHour)
        ->and($usage->promptTokens)->toBe(2048)
        ->and($usage->completionTokens)->toBe(503)
        ->and($usage->cacheReadInputTokens)->toBe(1800)
        ->and($usage->toArray()['cache_write_5m_input_tokens'])->toBe($fiveMinutes)
        ->and($usage->toArray()['cache_write_1h_input_tokens'])->toBe($oneHour);

    if ($fiveMinutes !== null && $oneHour !== null) {
        expect($usage->cacheWriteInputTokens)->toBe($usage->cacheWrite5mInputTokens + $usage->cacheWrite1hInputTokens);
    }
}

it('preserves the reported cache write split in text and structured responses', function (?int $fiveMinutes, ?int $oneHour, string $mode): void {
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_cache_usage',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-owned-fixture',
        'content' => [['type' => 'text', 'text' => '{"value":"ok"}']],
        'stop_reason' => 'end_turn',
        'usage' => cacheWriteUsage($fiveMinutes, $oneHour),
    ])]);

    if ($mode === 'structured') {
        $response = Prism::structured()
            ->using('anthropic', 'claude-owned-fixture')
            ->withSchema(new ObjectSchema('result', 'Result', [new StringSchema('value', 'Value')], ['value']))
            ->withPrompt('Owned fixture')
            ->asStructured();
    } else {
        $response = Prism::text()->using('anthropic', 'claude-owned-fixture')->withPrompt('Owned fixture')->asText();
    }

    assertCacheWriteUsage($response->usage, $fiveMinutes, $oneHour);
    assertCacheWriteUsage($response->steps->first()->usage, $fiveMinutes, $oneHour);
})->with('reported cache write lifetimes')->with(['text', 'structured']);

it('preserves cache lifetimes in decoded batch results', function (?int $fiveMinutes, ?int $oneHour): void {
    $jsonl = json_encode([
        'custom_id' => 'owned-cache-usage',
        'result' => [
            'type' => 'succeeded',
            'message' => [
                'id' => 'msg_cache_usage',
                'model' => 'claude-owned-fixture',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => cacheWriteUsage($fiveMinutes, $oneHour),
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    Http::fake(['api.anthropic.com/v1/messages/batches/owned/results' => Http::response($jsonl)])->preventStrayRequests();
    $items = (new Results(Http::baseUrl('https://api.anthropic.com/v1')))->handle('owned');
    expect($items)->toHaveCount(1);
    assertCacheWriteUsage($items[0]->usage, $fiveMinutes, $oneHour);
})->with('reported cache write lifetimes');

it('retains cache lifetimes through cumulative and sparse stream updates without adding them twice', function (?int $fiveMinutes, ?int $oneHour, bool $reportAtEnd): void {
    $usage = cacheWriteUsage($fiveMinutes, $oneHour);
    $replaceZero = ! $reportAtEnd && $fiveMinutes === 0 && $oneHour === 0;
    $restated = $reportAtEnd || $replaceZero;
    $start = $reportAtEnd ? ['input_tokens' => 0, 'output_tokens' => 0] : [...($replaceZero ? cacheWriteUsage(148, 100) : $usage), 'output_tokens' => 0];
    $events = [
        ['type' => 'message_start', 'message' => ['id' => 'msg_cache_usage', 'model' => 'claude-owned-fixture', 'usage' => $start]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => [], 'usage' => $restated ? [...$usage, 'output_tokens' => 100] : ['output_tokens' => 100]],
        ['type' => 'message_delta', 'delta' => [], 'usage' => $restated ? $usage : ['output_tokens' => 503]],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 503]],
        ['type' => 'message_stop'],
    ];
    $sse = implode('', array_map(fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event, JSON_THROW_ON_ERROR)."\n\n", $events));
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    $received = iterator_to_array(Prism::text()->using('anthropic', 'claude-owned-fixture')->withPrompt('Owned fixture')->asStream());
    $ends = array_values(array_filter($received, fn (StreamEvent $event): bool => $event instanceof StreamEndEvent));
    $steps = array_values(array_filter($received, fn (StreamEvent $event): bool => $event instanceof StepFinishEvent));
    expect($ends)->toHaveCount(1)->and($steps)->toHaveCount(1);
    assertCacheWriteUsage($ends[0]->usage, $fiveMinutes, $oneHour);
    assertCacheWriteUsage($steps[0]->usage, $fiveMinutes, $oneHour);
})->with('reported cache write lifetimes')->with([false, true]);

it('does not retain an earlier lifetime split after a changed write total without a split', function (): void {
    $events = [
        ['type' => 'message_start', 'message' => ['id' => 'msg_cache_changed', 'model' => 'claude-owned-fixture', 'usage' => [...cacheWriteUsage(148, 100), 'output_tokens' => 0]]],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 503, 'cache_creation_input_tokens' => 100]],
        ['type' => 'message_stop'],
    ];
    $sse = implode('', array_map(fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event, JSON_THROW_ON_ERROR)."\n\n", $events));
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);
    $ends = array_values(array_filter(iterator_to_array(Prism::text()->using('anthropic', 'claude-owned-fixture')->withPrompt('Owned fixture')->asStream()), fn (StreamEvent $event): bool => $event instanceof StreamEndEvent));
    expect($ends)->toHaveCount(1)
        ->and($ends[0]->usage->cacheWriteInputTokens)->toBe(100)
        ->and($ends[0]->usage->cacheWrite5mInputTokens)->toBeNull()
        ->and($ends[0]->usage->cacheWrite1hInputTokens)->toBeNull();
});

it('keeps the six original positional constructor arguments unchanged', function (): void {
    $usage = new Usage(11, 23, 5, 7, 3, 0.02);
    expect($usage->cost)->toBe(0.02)
        ->and($usage->thoughtTokens)->toBe(3)
        ->and($usage->cacheWrite5mInputTokens)->toBeNull()
        ->and($usage->cacheWrite1hInputTokens)->toBeNull();
});
