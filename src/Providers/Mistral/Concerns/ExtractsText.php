<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Concerns;

trait ExtractsText
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractText(array $data): string
    {
        $content = data_get($data, 'content');

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            return array_reduce($content, function (string $text, array $block): string {
                if (data_get($block, 'type') === 'text') {
                    $text .= data_get($block, 'text', '');
                }

                return $text;
            }, '');
        }

        return '';
    }
}
