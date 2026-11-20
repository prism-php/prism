<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Prism\Prism\Enums\CacheStability;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/*
|--------------------------------------------------------------------------
| prism#29: declare STABILITY, not breakpoints
|--------------------------------------------------------------------------
|
| Twelve providers report whether a request hit their prompt cache. Three
| accept a declaration about it, all in Anthropic's idiom. Accounting is
| portable; influence is not, and that asymmetry is the defect.
|
| What a caller actually knows is which parts of the request are stable across
| turns and which are not. That knowledge is provider-independent. WHERE the
| breakpoint goes is not, so core owns the first and each provider owns the
| second.
|
| For a mark-in-place provider the rule is a definition rather than a heuristic:
| the breakpoint belongs after the last stable element before the first
| volatile one, because that is what a breakpoint MEANS.
|
*/

/** How many cache breakpoints Anthropic would actually receive. */
function breakpoints(array $messages): int
{
    $count = 0;
    $mapped = MessageMap::map($messages);

    array_walk_recursive(
        $mapped,
        function (mixed $value, string|int $key) use (&$count): void {
            if ($key === 'type' && $value === 'ephemeral') {
                $count++;
            }
        },
    );

    return $count;
}

it('puts one breakpoint after the last stable message, before the first volatile one', function (): void {
    $messages = [
        (new UserMessage('The manual, which never changes'))->withCacheHint(CacheStability::Stable),
        (new AssistantMessage('Understood.'))->withCacheHint(CacheStability::Stable),
        (new UserMessage("Today's question"))->withCacheHint(CacheStability::Volatile),
    ];

    $mapped = MessageMap::map($messages);

    expect(breakpoints($messages))->toBe(1);

    // On the assistant message: the last stable one. Not the first, which
    // would cache less than the caller said was cacheable, and not the
    // volatile one, which would cache something that changes every turn.
    $marked = array_keys(array_filter(
        $mapped,
        fn (array $message): bool => str_contains(json_encode($message['content']) ?: '', 'ephemeral'),
    ));

    expect($marked)->toBe([1]);
});

it('caches the whole prompt when every message is stable', function (): void {
    $messages = [
        (new UserMessage('One'))->withCacheHint(CacheStability::Stable),
        (new AssistantMessage('Two'))->withCacheHint(CacheStability::Stable),
    ];

    $mapped = MessageMap::map($messages);

    expect(breakpoints($messages))->toBe(1)
        ->and(json_encode($mapped[1]) ?: '')->toContain('ephemeral');
});

it('marks nothing when the volatile part comes first', function (): void {
    // Nothing stable precedes the volatile message, so there is no prefix to
    // cache. This ordering also defeats prefix caching on every provider that
    // has no breakpoints at all -- a portable mistake with no symptom today.
    expect(breakpoints([
        (new UserMessage('Changes every turn'))->withCacheHint(CacheStability::Volatile),
        (new UserMessage('The manual'))->withCacheHint(CacheStability::Stable),
    ]))->toBe(0);
});

it('carries the ttl the hint was given', function (): void {
    $mapped = MessageMap::map([
        (new UserMessage('The manual'))->withCacheHint(CacheStability::Stable, ttl: '1h'),
        (new UserMessage('Question'))->withCacheHint(CacheStability::Volatile),
    ]);

    expect(json_encode($mapped) ?: '')->toContain('"ttl":"1h"');
});

it('changes nothing for a caller who sets no hints', function (): void {
    expect(breakpoints([
        new UserMessage('One'),
        new AssistantMessage('Two'),
    ]))->toBe(0);
});

it('leaves an explicit cacheType in charge where both are present', function (): void {
    // A caller who has already placed their own breakpoints knows Anthropic's
    // idiom. Deriving a second one from hints would spend a breakpoint they
    // did not ask for, against a limit of four.
    $messages = [
        (new UserMessage('The manual'))->withProviderOptions(['cacheType' => 'ephemeral']),
        (new UserMessage('Also stable'))->withCacheHint(CacheStability::Stable),
        (new UserMessage('Question'))->withCacheHint(CacheStability::Volatile),
    ];

    expect(breakpoints($messages))->toBe(1)
        ->and(json_encode(MessageMap::map($messages)[0]) ?: '')->toContain('ephemeral');
});
