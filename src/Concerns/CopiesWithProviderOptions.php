<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

/**
 * Provider options set on a COPY, for values that are reused across requests.
 *
 * WHY THIS EXISTS. `withProviderOptions()` replaces the options AND mutates the
 * object it is called on. That is right for a request builder, which is fluent
 * and mutable by design, and wrong for a message, a tool or a piece of media --
 * values an application keeps and sends again on the next step.
 *
 * A consumer rebuilding a request every step from the same conversation set a
 * cache breakpoint on the newest message each time. The mark stayed on that
 * object, so every earlier "newest message" kept its mark too, and the
 * breakpoints piled up past Anthropic's limit of four -- a hard request error.
 * They worked around it by cloning each message by hand before marking it. They
 * should not have needed to.
 *
 * NOT a change to `withProviderOptions()`. That method is shared with every
 * request builder, where `$request->withProviderOptions([...]);` written as a
 * statement is the normal pattern; making it return a copy would make those
 * options silently vanish. So the mutating setter stays, and values gain this.
 */
trait CopiesWithProviderOptions
{
    /**
     * A copy of this value, with these options MERGED over the ones it already
     * carries. The original is untouched.
     *
     * Merged rather than replaced, because replacing is the second half of the
     * same trap: marking a message that already carried an option silently
     * removed it. A new value wins when a key is given twice. The merge is
     * shallow, so a nested option is replaced whole -- which is what lets a
     * caller take a nested option away, and what a reader of the call expects.
     *
     *     $request = [...$history, $latest->copyWithProviderOptions(['cacheType' => 'ephemeral'])];
     *
     * @param  array<string, mixed>  $options
     */
    public function copyWithProviderOptions(array $options): static
    {
        $copy = clone $this;
        $copy->providerOptions = array_replace($this->providerOptions, $options);

        return $copy;
    }
}
