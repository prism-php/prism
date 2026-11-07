<?php

declare(strict_types=1);

namespace Prism\Prism\Events\Telemetry;

use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\AdvertisedTool;

/**
 * Dispatched when a generation begins, before the provider request is sent.
 *
 * `$request` is the originating request object, or null when
 * `prism.telemetry.capture_content` is disabled.
 *
 * `$tools` is its OWN parameter and is populated whatever `capture_content`
 * says. That is the same split {@see GenerationCompleted} makes for usage and
 * rate limits, for the same reason: a tool's NAME and a DIGEST of its
 * declaration are authored by the application, carrying nothing the user wrote
 * and nothing the model returned, while `$request` carries the prompt itself. A
 * listener commonly forwards this event somewhere that leaves the application,
 * so the split is made here rather than left to each listener to get right.
 *
 * Do not move `$tools` under the content gate. Providers cache a prompt PREFIX
 * and the tool array is part of it, so without this a consumer can diff most of
 * what was cached and not all of it — a cache miss caused by the system prompt
 * is diagnosable and one caused by a skill granting tools mid-turn is invisible.
 * A diagnosis that succeeds on one cause and is silently blind to another is
 * worse than none, because it reads as "the prefix is stable". That is G-45's
 * lesson in a second place: the signal is wanted in production, and production
 * is exactly where the content gate is off.
 *
 * The ORDER IS THE ORDER THE TOOLS WERE SENT IN, and it is load-bearing. A
 * provider's cached prefix is the tools array as serialised, so the same tools
 * in a different order is a different prefix and a cache miss. Sorting this list
 * — the reflex, since a set comparison feels more canonical — would make exactly
 * that case invisible, and invisible in the reassuring direction.
 */
readonly class GenerationStarted
{
    /**
     * @param  list<AdvertisedTool>  $tools  The tools the model was offered, in
     *                                       the order they were sent.
     */
    public function __construct(
        public TelemetryContext $context,
        public mixed $request = null,
        public array $tools = [],
    ) {}
}
