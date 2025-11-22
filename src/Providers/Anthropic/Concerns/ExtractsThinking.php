<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Illuminate\Support\Arr;

trait ExtractsThinking
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractThinking(array $data): array
    {
        if ($this->request->providerOptions('thinking.enabled') !== true) {
            return [];
        }

        $thinking = Arr::first(
            data_get($data, 'content', []),
            fn ($content): bool => data_get($content, 'type') === 'thinking'
        );

        return [
            'thinking' => data_get($thinking, 'thinking'),
            'thinking_signature' => data_get($thinking, 'signature'),
        ];
    }
}
