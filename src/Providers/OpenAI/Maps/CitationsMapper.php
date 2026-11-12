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
     * Annotation types the code interpreter adds for a file it wrote.
     */
    private const FILE_ANNOTATIONS = ['container_file_citation', 'file_path'];

    /**
     * @param  array<string, mixed>  $contentBlock
     */
    public static function mapFromOpenAI(array $contentBlock): ?MessagePartWithCitations
    {
        if (! isset($contentBlock['type']) || $contentBlock['type'] !== 'output_text') {
            return null;
        }

        // An annotation type added after this mapper must not fail a response that was already produced
        $annotations = array_filter(
            $contentBlock['annotations'] ?? [],
            fn (array $annotation): bool => in_array($annotation['type'] ?? null, ['file_citation', 'url_citation', ...self::FILE_ANNOTATIONS], true)
        );

        $citations = array_map(self::mapCitation(...), array_values($annotations));

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
        $additionalContent = [
            'responseStartIndex' => $citationData['start_index'] ?? null,
            'responseEndIndex' => $citationData['end_index'] ?? null,
        ];

        if (in_array($citationData['type'], self::FILE_ANNOTATIONS, true)) {
            $additionalContent += [
                'type' => $citationData['type'],
                'containerId' => $citationData['container_id'] ?? null,
                'fileId' => $citationData['file_id'] ?? null,
                'fileIndex' => $citationData['index'] ?? null,
            ];
        }

        return new Citation(
            sourceType: $sourceType = self::mapSourceType($citationData['type']),
            source: self::mapSource($citationData, $sourceType),
            sourceTitle: $citationData['title'] ?? null,
            additionalContent: $additionalContent
        );
    }

    protected static function mapSourceType(string $openaiType): CitationSourceType
    {
        return match ($openaiType) {
            'file_citation', 'container_file_citation', 'file_path' => CitationSourceType::Document,
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
                : $citationData['filename'] ?? $citationData['file_id'] ?? '';
        }

        return $citationData['url'] ?? '';
    }

    /**
     * @return array<string,mixed>
     */
    protected static function mapCitationToOpenAi(Citation $citation): array
    {
        $type = data_get($citation->additionalContent, 'type');

        if ($type === 'container_file_citation') {
            return [
                'type' => $type,
                'container_id' => data_get($citation->additionalContent, 'containerId'),
                'file_id' => data_get($citation->additionalContent, 'fileId'),
                'filename' => $citation->source,
                'start_index' => data_get($citation->additionalContent, 'responseStartIndex'),
                'end_index' => data_get($citation->additionalContent, 'responseEndIndex'),
            ];
        }

        if ($type === 'file_path') {
            return [
                'type' => $type,
                'file_id' => data_get($citation->additionalContent, 'fileId'),
                'index' => data_get($citation->additionalContent, 'fileIndex'),
            ];
        }

        return [
            'type' => 'url_citation',
            'start_index' => data_get($citation->additionalContent, 'responseStartIndex'),
            'end_index' => data_get($citation->additionalContent, 'responseEndIndex'),
            'url' => $citation->source,
            'title' => $citation->sourceTitle,
        ];
    }
}
