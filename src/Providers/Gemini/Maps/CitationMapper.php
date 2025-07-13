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
            $startIndex = data_get($groundingSupport, 'segment.startIndex');
            $endIndex = data_get($groundingSupport, 'segment.endIndex');

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
     * @param  array<array<string,string>>  $groundingChunks
     * @return Citation[]
     */
    protected static function mapGroundingChunkIndicesToCitations(array $groundingChunkIndices, array $groundingChunks): array
    {
        return array_map(
            fn (int $value): Citation => new Citation(
                sourceType: CitationSourceType::Url,
                source: data_get($groundingChunks, "{$value}.web.uri"),
                sourceTitle: data_get($groundingChunks, "{$value}.web.title"),
            ),
            $groundingChunkIndices
        );
    }
}
