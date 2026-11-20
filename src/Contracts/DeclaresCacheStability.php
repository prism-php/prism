<?php

declare(strict_types=1);

namespace Prism\Prism\Contracts;

use Prism\Prism\Enums\CacheStability;

/**
 * A part of a request that can say whether it is the same on the next turn.
 *
 * An interface rather than a duck-typed check, so a provider deriving a
 * breakpoint from stability asks the type system what a message can answer,
 * and so anything else a caller puts in the messages array is simply not
 * asked. See {@see CacheStability}.
 */
interface DeclaresCacheStability
{
    public function cacheStability(): ?CacheStability;

    /** The provider-specific lifetime the caller asked for, if any. */
    public function cacheTtl(): ?string;
}
