<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Concerns;

trait ExtractsThinking
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function extractThinking(array $data): array
    {
        $content = data_get($data, 'content', []);

        if (! is_array($content)) {
            return [];
        }

        $thinkingText = '';

        foreach ($content as $block) {
            if (data_get($block, 'type') === 'thinking') {
                $thinkingBlocks = data_get($block, 'thinking', []);
                foreach ($thinkingBlocks as $thinkingBlock) {
                    $thinkingText .= data_get($thinkingBlock, 'text', '');
                }
            }
        }

        return $thinkingText !== '' ? ['thinking' => $thinkingText] : [];
    }
}
