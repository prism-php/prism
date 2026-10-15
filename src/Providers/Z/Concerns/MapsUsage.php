<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Z\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait MapsUsage
{
    /**
     * Z's `prompt_tokens` includes the tokens served from the cache, so the
     * prompt bucket is what remains once `cached_tokens` is taken out. Z
     * doesn't report reasoning tokens separately.
     *
     * @param  array<string, mixed>  $usage
     */
    protected function mapUsage(array $usage): Usage
    {
        $cacheReadTokens = data_get($usage, 'prompt_tokens_details.cached_tokens');
        $cacheReadTokens = $cacheReadTokens === null ? null : (int) $cacheReadTokens;

        return new Usage(
            promptTokens: max(0, (int) data_get($usage, 'prompt_tokens', 0) - (int) $cacheReadTokens),
            completionTokens: (int) data_get($usage, 'completion_tokens', 0),
            cacheReadInputTokens: $cacheReadTokens,
        );
    }
}
