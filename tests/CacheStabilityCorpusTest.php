<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\CacheStability;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\Support\CacheHints;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * The cross-language `cache-stability-hints` corpus from `prism-parity`.
 *
 * This package is the REFERENCE, so this file proves the corpus has not
 * drifted from the code it was recorded against — not that the derivation is
 * right, which the per-provider tests do. When a port later says "I match the
 * reference", this is what makes that sentence mean something.
 *
 * Two of three columns are empty today (G-64). The suite is the failing corpus
 * the ports get built against.
 */
function cacheHintCorpus(): array
{
    return json_decode(
        (string) file_get_contents(__DIR__.'/Fixtures/cache-stability-hints.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['cases'];
}

function cacheHintMessages(array $case): array
{
    return array_map(function (array $row): AssistantMessage|UserMessage {
        $message = $row['role'] === 'assistant'
            ? new AssistantMessage($row['text'])
            : new UserMessage($row['text']);

        if (($row['hint'] ?? null) !== null) {
            $message->withCacheHint(CacheStability::from($row['hint']), $row['ttl'] ?? null);
        }

        if (isset($row['provider_options'])) {
            $message->withProviderOptions($row['provider_options']);
        }

        return $message;
    }, $case['messages']);
}

/** Which message carries the breakpoint, under the mark-in-place model. */
function cacheHintBreakpointIndex(array $messages): ?int
{
    foreach (MessageMap::map($messages) as $index => $mapped) {
        if (str_contains(json_encode($mapped) ?: '', 'cache_control')) {
            return $index;
        }
    }

    return null;
}

it('derives what the corpus records for php', function (array $case): void {
    $messages = cacheHintMessages($case);
    $expected = $case['expected']['php'];

    // Mark-in-place.
    expect(cacheHintBreakpointIndex($messages))->toBe($expected['breakpoint_index']);

    // Automatic prefix: the ordering is the only thing to say.
    expect(CacheHints::firstMisorderedPair($messages) !== null)->toBe($expected['ordering_warning']);

    // Create-and-reference: how much becomes the resource. Null when there is
    // no stable prefix, and null when there is no volatile remainder either --
    // a generation with nothing left to ask is not a generation.
    [$prefix, $rest] = CacheHints::split($messages);
    $cachedPrefixLength = ($prefix === [] || $rest === []) ? null : count($prefix);

    expect($cachedPrefixLength)->toBe($expected['cached_prefix_length']);

    if (isset($expected['ttl_seconds'])) {
        expect(CacheHints::ttlToSeconds($case['messages'][0]['ttl']))->toBe($expected['ttl_seconds']);
    }
})->with(fn (): array => array_map(fn (array $case): array => [$case], cacheHintCorpus()));

it('warns exactly once for the row that says once', function (): void {
    // "Warns" and "warns once" are different behaviours, and a port will not
    // distinguish them unless something asks. The corpus row says once; this
    // is what makes that row a check rather than a note.
    Log::spy();

    $case = array_values(array_filter(
        cacheHintCorpus(),
        fn (array $case): bool => $case['id'] === 'hint-0006',
    ))[0];

    CacheHints::warnIfMisordered(cacheHintMessages($case));

    Log::shouldHaveReceived('warning')->once();
});

it('still contains the rows that stop it agreeing about nothing', function (): void {
    $ids = array_column(cacheHintCorpus(), 'id');

    // hint-0001 declares nothing and must derive nothing; hint-0005 is
    // volatile-first, where the correct answer is to mark NOTHING and a port
    // doing more work would look like a port doing better.
    expect($ids)->toContain('hint-0001')
        ->and($ids)->toContain('hint-0005');

    $derived = array_filter(array_map(
        fn (array $case): ?int => $case['expected']['php']['breakpoint_index'],
        cacheHintCorpus(),
    ), fn (?int $index): bool => $index !== null);

    // And it is not a corpus of nulls: something in here really is derived.
    expect(count($derived))->toBeGreaterThanOrEqual(3);
});
