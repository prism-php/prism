<?php

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Prism\Prism\ValueObjects\Meta;

trait ExtractsMeta
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractsMeta(array $data): Meta
    {
        return new Meta(
            id: data_get($data, 'id'),
            model: data_get($data, 'model'),
            rateLimits: [],
        );
    }
}
