<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait MapsUsage
{
    /**
     * Gemini's `promptTokenCount` includes cached content, while tool-use input and
     * thoughts are reported beside it. The prompt bucket drops the cached part and
     * adds tool-use input; the completion bucket adds thoughts to the candidates.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mapUsage(array $data): Usage
    {
        $cachedTokens = data_get($data, 'usageMetadata.cachedContentTokenCount');
        $thoughtTokens = data_get($data, 'usageMetadata.thoughtsTokenCount');

        $promptTokens = (int) data_get($data, 'usageMetadata.promptTokenCount', 0)
            - (int) $cachedTokens
            + (int) data_get($data, 'usageMetadata.toolUsePromptTokenCount', 0);

        return new Usage(
            promptTokens: max(0, $promptTokens),
            completionTokens: (int) data_get($data, 'usageMetadata.candidatesTokenCount', 0) + (int) $thoughtTokens,
            cacheReadInputTokens: $cachedTokens === null ? null : (int) $cachedTokens,
            thoughtTokens: $thoughtTokens === null ? null : (int) $thoughtTokens,
        );
    }
}
