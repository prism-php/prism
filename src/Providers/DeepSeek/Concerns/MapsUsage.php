<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\DeepSeek\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait MapsUsage
{
    /**
     * DeepSeek's `prompt_tokens` is `prompt_cache_hit_tokens` plus
     * `prompt_cache_miss_tokens`, so the prompt bucket is what remains once the
     * cache hits are taken out. `prompt_tokens_details.cached_tokens` is an
     * alias of `prompt_cache_hit_tokens`.
     *
     * @param  array<string, mixed>  $usage
     */
    protected function mapUsage(array $usage): Usage
    {
        $cacheReadTokens = $this->reportedTokenCount($usage, 'prompt_cache_hit_tokens')
            ?? $this->reportedTokenCount($usage, 'prompt_tokens_details.cached_tokens');

        return new Usage(
            promptTokens: max(0, (int) data_get($usage, 'prompt_tokens', 0) - (int) $cacheReadTokens),
            completionTokens: (int) data_get($usage, 'completion_tokens', 0),
            cacheReadInputTokens: $cacheReadTokens,
            thoughtTokens: $this->reportedTokenCount($usage, 'completion_tokens_details.reasoning_tokens'),
        );
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    protected function reportedTokenCount(array $usage, string $key): ?int
    {
        $tokens = data_get($usage, $key);

        return $tokens === null ? null : (int) $tokens;
    }
}
