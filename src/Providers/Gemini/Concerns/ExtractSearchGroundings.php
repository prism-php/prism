<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Concerns;

use Prism\Prism\Providers\Gemini\Maps\SearchGroundingMap;

trait ExtractSearchGroundings
{
    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function extractSearchGroundingContent(array $data): array
    {
        if (data_get($data, 'candidates.0.groundingMetadata') === null) {
            return [];
        }

        return [
            'searchEntryPoint' => data_get($data, 'candidates.0.groundingMetadata.searchEntryPoint.renderedContent', ''),
            'searchQueries' => data_get($data, 'candidates.0.groundingMetadata.webSearchQueries', []),
            'groundingSupports' => SearchGroundingMap::map(
                data_get($data, 'candidates.0.groundingMetadata.groundingSupports', []),
                data_get($data, 'candidates.0.groundingMetadata.groundingChunks', [])
            ),
        ];
    }
}
