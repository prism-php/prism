<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Prism\Prism\Providers\Gemini\ValueObjects\MessagePartWithSearchGroundings;
use Prism\Prism\Providers\Gemini\ValueObjects\SearchGrounding;

class SearchGroundingMap
{
    /**
     * @param  array<string,mixed>  $groundingSupports
     * @param  array<array<string,array<string,string>>>  $groundingChunks
     * @return MessagePartWithSearchGroundings[]
     */
    public static function map(array $groundingSupports, array $groundingChunks): array
    {
        return array_map(
            fn ($groundingSupport): \Prism\Prism\Providers\Gemini\ValueObjects\MessagePartWithSearchGroundings => new MessagePartWithSearchGroundings(
                text: data_get($groundingSupport, 'segment.text', ''),
                startIndex: data_get($groundingSupport, 'segment.startIndex', 0),
                endIndex: data_get($groundingSupport, 'segment.endIndex', 0),
                groundings: static::mapGroundings($groundingSupport, $groundingChunks)
            ),
            $groundingSupports
        );
    }

    /**
     * @param  array<string,mixed>  $groundingSupport
     * @param  array<array<string,array<string,string>>>  $groundingChunks
     * @return SearchGrounding[]
     */
    protected static function mapGroundings(array $groundingSupport, array $groundingChunks): array
    {
        return array_map(
            function ($index) use ($groundingChunks, $groundingSupport): \Prism\Prism\Providers\Gemini\ValueObjects\SearchGrounding {
                $i = 0;

                $grounding = new SearchGrounding(
                    title: data_get($groundingChunks[$index], 'web.title', ''),
                    uri: data_get($groundingChunks[$index], 'web.uri', ''),
                    confidence: data_get($groundingSupport, "confidenceScores.$i", 0.0)
                );

                $i++;

                return $grounding;
            },
            data_get($groundingSupport, 'groundingChunkIndices', [])
        );
    }
}
