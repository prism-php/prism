<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Providers\Anthropic\Maps\CitationsMapper;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

trait ExtractsCitations
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<int, MessagePartWithCitations>|null
     */
    protected function extractCitations(array $data): ?array
    {
        if (data_get($data, 'content.*.citations', []) === []) {
            return null;
        }

        return array_values(Arr::whereNotNull(
            Arr::map(data_get($data, 'content', []), CitationsMapper::mapFromAnthropic(...))
        ));
    }
}
