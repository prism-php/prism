<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Groq\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait MapsUsage
{
    /**
     * Groq's `prompt_tokens` includes cached input and `completion_tokens`
     * includes reasoning.
     *
     * @param  array<string, mixed>  $usage
     */
    protected function mapUsage(array $usage): Usage
    {
        $cachedTokens = data_get($usage, 'prompt_tokens_details.cached_tokens');
        $reasoningTokens = data_get($usage, 'completion_tokens_details.reasoning_tokens');

        return new Usage(
            promptTokens: max(0, (int) data_get($usage, 'prompt_tokens', 0) - (int) $cachedTokens),
            completionTokens: (int) data_get($usage, 'completion_tokens', 0),
            cacheReadInputTokens: $cachedTokens === null ? null : (int) $cachedTokens,
            thoughtTokens: $reasoningTokens === null ? null : (int) $reasoningTokens,
        );
    }
}
