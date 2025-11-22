<?php

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait ExtractsAdditionalContent
{
    /**
     * @return array<string, mixed>
     *
     * @throws \JsonException
     */
    protected function extractsAdditionalContent(array $data): array
    {
        return Arr::whereNotNull([
            'citations' => data_get($data, 'citations'),
            'search_results' => data_get($data, 'search_results'),
            'reasoning' => $this->extractsReasoning(data_get($data, 'choices.{last}.message.content')),
        ]);
    }

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
