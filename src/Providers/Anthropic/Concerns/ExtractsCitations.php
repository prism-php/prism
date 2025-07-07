<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations;

trait ExtractsCitations
{
    /**
     * @param  array<string, mixed>  $data
     * @return null|MessagePartWithCitations[]
     */
    protected function extractCitations(array $data): ?array
    {
        if (Arr::whereNotNull(data_get($data, 'content.*.citations')) === []) {
            return null;
        }

        return Arr::map(data_get($data, 'content', []), fn ($contentBlock): \Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations => MessagePartWithCitations::fromContentBlock($contentBlock));
    }
}
