<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use InvalidArgumentException;
use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

class CitationsMapper
{
    /**
     * @param  array<string, mixed>  $contentBlock
     */
    public static function mapFromOpenAI(array $contentBlock): ?MessagePartWithCitations
    {
        if (! isset($contentBlock['type']) || $contentBlock['type'] !== 'output_text') {
            return null;
        }

        $citations = array_map(
            self::mapCitation(...),
            $contentBlock['annotations'] ?? []
        );

        return new MessagePartWithCitations(
            outputText: $contentBlock['text'] ?? '',
            citations: $citations,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function mapToOpenAI(MessagePartWithCitations $messagePartWithCitations): array
    {
        $annotations = array_map(
            self::mapCitationToOpenAi(...),
            $messagePartWithCitations->citations
        );

        return [
            'type' => 'output_text',
            'text' => $messagePartWithCitations->outputText,
            'annotations' => $annotations,
        ];
    }

    /**
     * @param  array<string, mixed>  $citationData
     */
    protected static function mapCitation(array $citationData): Citation
    {
        return new Citation(
            sourceType: $sourceType = self::mapSourceType($citationData['type']),
            source: self::mapSource($citationData, $sourceType),
            sourceTitle: $citationData['title'] ?? null,
            additionalContent: [
                'responseStartIndex' => $citationData['start_index'] ?? null,
                'responseEndIndex' => $citationData['end_index'] ?? null,
            ]
        );
    }

    protected static function mapSourceType(string $openaiType): CitationSourceType
    {
        return match ($openaiType) {
            'file_citation' => CitationSourceType::Document,
            'url_citation' => CitationSourceType::Url,
            default => throw new InvalidArgumentException("Unknown citation source type: {$openaiType}"),
        };
    }

    /**
     * @param  array<string, mixed>  $citationData
     */
    protected static function mapSource(array $citationData, CitationSourceType $sourceType): string|int
    {
        if ($sourceType === CitationSourceType::Document) {
            return isset($citationData['filename'], $citationData['index'])
                ? $citationData['filename'].':'.$citationData['index']
                : $citationData['filename'] ?? '';
        }

        return $citationData['url'] ?? '';
    }

    /**
     * @return array<string,mixed>
     */
    protected static function mapCitationToOpenAi(Citation $citation): array
    {
        return [
            'type' => 'url_citation',
            'start_index' => data_get($citation->additionalContent, 'responseStartIndex'),
            'end_index' => data_get($citation->additionalContent, 'responseEndIndex'),
            'url' => $citation->source,
            'title' => $citation->sourceTitle,
        ];
    }
}
