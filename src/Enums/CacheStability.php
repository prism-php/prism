<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

/**
 * Whether a part of a request is the same on the next turn.
 *
 * THIS IS THE PORTABLE HALF OF PROMPT CACHING. Twelve providers report whether
 * a request hit their cache; three accept a declaration about it, all in
 * Anthropic's idiom of explicit breakpoints. Accounting is portable, influence
 * is not, and promoting one provider's marker to core would make the field a
 * no-op or a lie on the rest.
 *
 * What a caller knows is which parts of their request are stable and which
 * change every turn. That is provider-independent. WHERE a breakpoint goes,
 * or whether the concept exists at all, is the provider's business:
 *
 * - **mark-in-place** (Anthropic-family): the breakpoint is placed after the
 *   last stable element before the first volatile one, which is what a
 *   breakpoint means. No caller counts markers or learns the limit of four.
 * - **automatic prefix** (OpenAI-family): nothing goes on the wire. The
 *   declaration is still worth making, because a volatile element ordered
 *   before a stable one defeats prefix caching on every provider, and that
 *   mistake has no symptom today.
 * - **create-and-reference** (Gemini/Vertex): the stable prefix is exactly
 *   what belongs in a `cachedContents` resource.
 *
 * A provider that cannot act on the hint IGNORES it rather than throwing, and
 * that is a deliberate exception to the contract rule that unsupported means
 * throw. The caller asked for cheaper, not for a feature; the bytes sent are
 * identical either way, and a cache miss is already visible in `Usage`.
 */
enum CacheStability: string
{
    /** The same on the next turn: a manual, a system prompt, settled history. */
    case Stable = 'stable';

    /** Different on the next turn: this turn's question, a timestamp, a search result. */
    case Volatile = 'volatile';
}
