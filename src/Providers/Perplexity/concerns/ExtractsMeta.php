<?php

namespace Prism\Prism\Providers\Perplexity\concerns;

use Prism\Prism\ValueObjects\Meta;

trait ExtractsMeta
{
    protected function extractsMeta(array $data): Meta
    {
        return new Meta(
            id: data_get($data, 'id'),
            model: data_get($data, 'model'),
            rateLimits: [],
        );
    }
}
