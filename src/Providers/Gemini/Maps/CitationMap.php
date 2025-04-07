<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Prism\Prism\Providers\Gemini\ValueObjects\MessagePartWithSearchGroundings;
use Prism\Prism\Providers\Gemini\ValueObjects\SearchGrounding;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

class CitationMap
{
    /**
     * @param  array<string, mixed>  $groundingSupports
     * @param  array<array<string, array<string, string>>>  $groundingChunks
     * @return MessagePartWithCitations[]
     */
    public static function mapGroundings(array $groundingSupports, array $groundingChunks): array
    {
        $geminiParts = SearchGroundingMap::map($groundingSupports, $groundingChunks);

        return array_map(
            fn (MessagePartWithSearchGroundings $part): MessagePartWithCitations => self::mapMessagePart($part),
            $geminiParts
        );
    }

    /**
     * Maps Gemini MessagePartWithSearchGroundings to standardized MessagePartWithCitations
     */
    public static function mapMessagePart(MessagePartWithSearchGroundings $geminiPart): MessagePartWithCitations
    {
        return new MessagePartWithCitations(
            text: $geminiPart->text,
            citations: array_map(
                fn (SearchGrounding $grounding): Citation => self::mapGrounding($grounding),
                $geminiPart->groundings
            ),
            startPosition: $geminiPart->startIndex,
            endPosition: $geminiPart->endIndex
        );
    }

    /**
     * Maps Gemini SearchGrounding to standardized Citation
     */
    public static function mapGrounding(SearchGrounding $grounding): Citation
    {
        return new Citation(
            text: '', // Gemini doesn't provide the exact text being cited
            startPosition: 0, // These are set at the MessagePart level instead
            endPosition: 0,   // These are set at the MessagePart level instead
            sourceIndex: 0, // Gemini doesn't have a direct concept of source index
            sourceTitle: $grounding->title,
            sourceUrl: $grounding->uri,
            confidence: $grounding->confidence,
            type: 'url' // Gemini groundings are always URLs
        );
    }
}
