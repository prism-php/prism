<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Concerns;

use Prism\Prism\Providers\Gemini\Maps\CitationMap;
use Prism\Prism\Providers\Gemini\Maps\SearchGroundingMap;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

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

        $groundingSupports = data_get($data, 'candidates.0.groundingMetadata.groundingSupports', []);
        $groundingChunks = data_get($data, 'candidates.0.groundingMetadata.groundingChunks', []);

        return [
            'searchEntryPoint' => data_get($data, 'candidates.0.groundingMetadata.searchEntryPoint.renderedContent', ''),
            'searchQueries' => data_get($data, 'candidates.0.groundingMetadata.webSearchQueries', []),
            'groundingSupports' => SearchGroundingMap::map(
                $groundingSupports,
                $groundingChunks
            ),
            'citations' => $this->extractCitations($data),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return null|MessagePartWithCitations[]
     */
    protected function extractCitations(array $data): ?array
    {
        if (data_get($data, 'candidates.0.groundingMetadata.groundingSupports') === null) {
            return null;
        }

        return CitationMap::mapGroundings(
            data_get($data, 'candidates.0.groundingMetadata.groundingSupports', []),
            data_get($data, 'candidates.0.groundingMetadata.groundingChunks', [])
        );
    }
}
