<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Contracts\DeclaresCacheStability;
use Prism\Prism\Enums\CacheStability;

/**
 * The portable half of prompt caching: an ordering every provider cares about.
 *
 * A provider with automatic prefix caching has no breakpoint to place, so
 * stability hints put nothing on its wire. They are still worth declaring,
 * because ORDER decides whether prefix caching works at all. A cache matches a
 * PREFIX, so everything from the first element that changed onwards is
 * uncacheable — on every provider, including the ones with explicit
 * breakpoints. A volatile message placed before a stable one therefore throws
 * away the cache on the whole prompt that follows it.
 *
 * WHY THIS IS WORTH CODE. The mistake has no symptom. The request succeeds,
 * the answer is right, and the only evidence is the bill — which is exactly
 * the class of defect a consumer found in this ecosystem twice in one week,
 * each time by reading a number nobody else was reading.
 *
 * WHY IT WARNS RATHER THAN THROWS, against the contract rule that unsupported
 * means throw: the caller asked for cheaper, not for a feature. The request is
 * valid, the bytes are unchanged, and the answer is identical — refusing to
 * send it would be a worse outcome than sending it expensively. The ordering
 * is also sometimes deliberate, and a package cannot tell the difference.
 */
class CacheHints
{
    /**
     * Warn once if a volatile part precedes a stable one.
     *
     * @param  array<int, mixed>  $messages
     */
    public static function warnIfMisordered(array $messages): void
    {
        $pair = self::firstMisorderedPair($messages);

        if ($pair === null) {
            return;
        }

        [$volatile, $stable] = $pair;

        // ONE finding per request, not one per pair: the fix is the same edit,
        // and a warning repeated for every pair is one a reader filters out.
        // Both positions are named, because "check your ordering" in a
        // conversation of forty messages is not something anyone can act on.
        Log::warning(sprintf(
            'Prism: the message at position %d is declared volatile and the one at position %d is declared stable. '.
            'A prompt cache matches a prefix, so nothing from position %d onwards can be cached on any provider. '.
            'Move the stable content before the volatile content to cache it.',
            $volatile,
            $stable,
            $volatile,
        ));
    }

    /**
     * The first volatile-then-stable pair, as [volatile index, stable index].
     *
     * @param  array<int, mixed>  $messages
     * @return array{int, int}|null
     */
    public static function firstMisorderedPair(array $messages): ?array
    {
        $volatile = null;

        foreach (array_values($messages) as $index => $message) {
            if (! $message instanceof DeclaresCacheStability) {
                continue;
            }

            $stability = $message->cacheStability();

            if ($stability === CacheStability::Volatile && $volatile === null) {
                $volatile = $index;

                continue;
            }

            if ($stability === CacheStability::Stable && $volatile !== null) {
                return [$volatile, $index];
            }
        }

        return null;
    }

    /**
     * The declared-stable prefix, and everything from the first volatile part on.
     *
     * The split a create-and-reference provider needs: the prefix becomes the
     * cached resource and the remainder is what the turn actually asks. It
     * lives here, beside the ordering rule, because both are statements about
     * the same declaration -- and a second copy in each provider is how two
     * providers end up disagreeing about what "stable" meant.
     *
     * @param  array<int, mixed>  $messages
     * @return array{0: array<int, mixed>, 1: array<int, mixed>}
     */
    public static function split(array $messages): array
    {
        $messages = array_values($messages);

        foreach ($messages as $index => $message) {
            if ($message instanceof DeclaresCacheStability && $message->cacheStability() === CacheStability::Volatile) {
                return [array_slice($messages, 0, $index), array_slice($messages, $index)];
            }
        }

        return [$messages, []];
    }

    /**
     * A ttl written for a provider ("1h", "3600") as seconds.
     *
     * Floored at a minute: a resource that expires before the next turn costs
     * the write and returns nothing, which is worse than not caching.
     */
    public static function ttlToSeconds(string $ttl, int $default = 3600): int
    {
        if (preg_match('/^(\d+)\s*([smhd])?$/i', trim($ttl), $matches) !== 1) {
            return $default;
        }

        return max(60, ((int) $matches[1]) * match (strtolower($matches[2] ?? 's')) {
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            default => 1,
        });
    }
}
