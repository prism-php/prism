<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Providers\OpenAI\Maps\CitationsMapper;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

trait ExtractsCitations
{
    /**
     * @param  array<string,mixed>  $responseData
     * @return null|MessagePartWithCitations[]
     */
    protected function extractCitations(array $responseData): ?array
    {
        $contentBlock = data_get($responseData, 'output.{last}.content.{last}', []);

        if (data_get($contentBlock, 'annotations', []) === []) {
            return null;
        }

        return Arr::whereNotNull([CitationsMapper::mapFromOpenAI($contentBlock)]);
    }
}
