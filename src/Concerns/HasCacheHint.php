<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Enums\CacheStability;

/**
 * Says whether this part of the request is the same on the next turn.
 *
 * See {@see CacheStability} for why this is the portable
 * half of prompt caching and a provider's breakpoint is not.
 */
trait HasCacheHint
{
    protected ?CacheStability $cacheStability = null;

    protected ?string $cacheTtl = null;

    /**
     * Declare this part stable or volatile, and mutate in place.
     *
     * IN PLACE DELIBERATELY, where `copyWithProviderOptions()` returns a copy.
     * The difference is what the two statements mean. A cache BREAKPOINT is
     * about one request -- marking the newest message every turn, which is how
     * marks piled up past Anthropic's limit of four and why the copying
     * variant exists. Stability is a fact about the CONTENT: a manual that is
     * stable this turn is stable every turn, and a hint that fell off after
     * one request would have to be re-declared on each one.
     */
    public function withCacheHint(CacheStability $stability, ?string $ttl = null): static
    {
        $this->cacheStability = $stability;
        $this->cacheTtl = $ttl;

        return $this;
    }

    public function cacheStability(): ?CacheStability
    {
        return $this->cacheStability;
    }

    public function cacheTtl(): ?string
    {
        return $this->cacheTtl;
    }
}
