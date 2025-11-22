<?php

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Illuminate\Support\Str;

trait ExtractsReasoning
{
    /***
     * It extracts the reasoning part from a given string content which might include the reasoning tags.
     * e.g <think>reasoning</think> or ```json ... ```
     *
     * @param string $content
     *
     * @return string|null
     * @throws \JsonException
     */
    protected function extractsReasoning(string $content): ?string
    {
        $str = Str::of($content);
        if (! $str->contains('<think>')) {
            return null;
        }

        return $str->between('<think>', '</think>')->trim()->toString();
    }
}
