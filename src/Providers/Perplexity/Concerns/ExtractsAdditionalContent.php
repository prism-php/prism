<?php

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Illuminate\Support\Arr;

trait ExtractsAdditionalContent
{
    /**
     * @return array<string, mixed>
     */
    protected function extractsAdditionalContent(array $data): array
    {
        return Arr::whereNotNull([
            'citations' => data_get($data, 'citations'),
            'search_results' => data_get($data, 'search_results'),
        ]);
    }
}
