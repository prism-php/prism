<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

class CitationMapper
{
    /**
     * @param  array<string,mixed>  $candidate
     * @return MessagePartWithCitations[]
     * */
    public static function mapFromGemini(array $candidate): array
    {
        $lastWrittenCharacter = -1;
        $messageParts = [];

        $originalOutput = data_get($candidate, 'content.parts.0.text', '');

        $groundingSupports = data_get($candidate, 'groundingMetadata.groundingSupports', []);

        $groundingChunks = data_get($candidate, 'groundingMetadata.groundingChunks', []);

        foreach ($groundingSupports as $groundingSupport) {
            $startIndex = data_get($groundingSupport, 'segment.startIndex') ?? 0;
            $endIndex = data_get($groundingSupport, 'segment.endIndex') ?? strlen((string) $originalOutput);

            if ($startIndex - 1 > $lastWrittenCharacter) {
                $messageParts[] = new MessagePartWithCitations(
                    outputText: substr((string) $originalOutput, $lastWrittenCharacter + 1, $startIndex - $lastWrittenCharacter - 1),
                    citations: [],
                );

                $lastWrittenCharacter = $startIndex - 1;
            }

            $messageParts[] = new MessagePartWithCitations(
                outputText: substr((string) $originalOutput, $startIndex, $endIndex - $startIndex + 1),
                citations: self::mapGroundingChunkIndicesToCitations(
                    data_get($groundingSupport, 'groundingChunkIndices', []),
                    $groundingChunks
                )
            );

            $lastWrittenCharacter = $endIndex;
        }

        return $messageParts;
    }

    /**
     * @param  array<int>  $groundingChunkIndices
     * @param  array<int, array<string, mixed>>  $groundingChunks
     * @return Citation[]
     */
    protected static function mapGroundingChunkIndicesToCitations(array $groundingChunkIndices, array $groundingChunks): array
    {
        return array_map(
            function (int $value) use ($groundingChunks): Citation {
                $chunk = $groundingChunks[$value] ?? [];

                if (isset($chunk['web']) && is_array($chunk['web'])) {
                    $web = $chunk['web'];

                    return new Citation(
                        sourceType: CitationSourceType::Url,
                        source: is_string($web['uri'] ?? null) ? $web['uri'] : '',
                        sourceTitle: is_string($web['title'] ?? null) ? $web['title'] : null,
                    );
                }

                if (isset($chunk['retrievedContext']) && is_array($chunk['retrievedContext'])) {
                    $context = $chunk['retrievedContext'];

                    return new Citation(
                        sourceType: CitationSourceType::Url,
                        source: is_string($context['fileSearchStore'] ?? null) ? $context['fileSearchStore'] : '',
                        sourceTitle: is_string($context['title'] ?? null) ? $context['title'] : null,
                    );
                }

                return new Citation(
                    sourceType: CitationSourceType::Url,
                    source: '',
                    sourceTitle: null,
                );
            },
            $groundingChunkIndices
        );
    }
}
