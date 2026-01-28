<?php

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

trait ExtractsStructuredOutput
{
    /***
     * It extracts structured JSON output from a given string content which might include the reasoning tags or code block formatting.
     * e.g <think>...</think> or ```json ... ```
     *
     * @param string $content
     *
     * @return array
     * @throws \JsonException
     */
    protected function parseStructuredOutput(string $content): array
    {
        $str = Str::of($content)
            ->trim()
            ->when(
                static fn (Stringable $str) => $str->contains('</think>'),
                static fn (Stringable $str) => $str->after('</think>')->trim()
            )
            ->when(
                static fn (Stringable $str) => $str->startsWith('```json'),
                static fn (Stringable $str) => $str->after('```json')->trim()
            )
            ->when(
                static fn (Stringable $str) => $str->startsWith('```'),
                static fn (Stringable $str) => $str->substr(3)->trim()
            )
            ->when(
                static fn (Stringable $str) => $str->endsWith('```'),
                static fn (Stringable $str) => $str->substr(0, $str->length('UTF-8') - 3)->trim()
            );

        return json_decode($str, associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
