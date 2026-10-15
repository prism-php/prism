<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Providers\Anthropic\Support\ContentBlocks;

trait ExtractsThinking
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractThinking(array $data): array
    {
        $thinkingBlocks = ContentBlocks::thinkingBlocks(
            ContentBlocks::servingBlocks(data_get($data, 'content') ?? [])
        );

        if ($thinkingBlocks === []) {
            return [];
        }

        $firstThinking = Arr::first(
            $thinkingBlocks,
            fn (array $block): bool => ($block['type'] ?? null) === 'thinking'
        );

        return [
            'thinking_blocks' => $thinkingBlocks,
            'thinking' => $firstThinking['thinking'] ?? null,
            'thinking_signature' => $firstThinking['signature'] ?? null,
        ];
    }
}
