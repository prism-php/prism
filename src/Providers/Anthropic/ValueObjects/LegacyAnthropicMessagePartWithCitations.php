<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\ValueObjects;

use InvalidArgumentException;

class LegacyAnthropicMessagePartWithCitations
{
    /**
     * @param  LegacyAnthropicCitation[]  $citations
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $citations = []
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromContentBlock(array $data): self
    {
        return new self(
            $data['text'] ?? null,
            array_map(function (array $citation): LegacyAnthropicCitation {
                $indexPropertyCommonPart = match ($citation['type']) {
                    'page_location' => 'page_number',
                    'char_location' => 'char_index',
                    'content_block_location' => 'block_index',
                    'web_search_result_location' => null,
                    default => throw new InvalidArgumentException("Unknown citation type: {$citation['type']}"),
                };

                return new LegacyAnthropicCitation(
                    type: $citation['type'],
                    citedText: data_get($citation, 'cited_text'),
                    startIndex: $indexPropertyCommonPart ? data_get($citation, "start_$indexPropertyCommonPart") : null,
                    endIndex: $indexPropertyCommonPart ? data_get($citation, "end_$indexPropertyCommonPart") : null,
                    documentIndex: data_get($citation, 'document_index'),
                    documentTitle: data_get($citation, 'document_title') ?? data_get($citation, 'title'),
                    url: data_get($citation, 'url')
                );
            }, $data['citations'] ?? [])
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toContentBlock(): array
    {
        return [
            'type' => 'text',
            'text' => $this->text,
            'citations' => array_map(function (LegacyAnthropicCitation $citation): array {
                $indexPropertyCommonPart = match ($citation->type) {
                    'page_location' => 'page_number',
                    'char_location' => 'char_index',
                    'content_block_location' => 'block_index',
                    default => throw new InvalidArgumentException("Unknown citation type: {$citation->type}"),
                };

                return [
                    'type' => $citation->type,
                    'cited_text' => $citation->citedText,
                    'document_index' => $citation->documentIndex,
                    'document_title' => $citation->documentTitle,
                    "start_$indexPropertyCommonPart" => $citation->startIndex,
                    "end_$indexPropertyCommonPart" => $citation->endIndex,
                ];
            }, $this->citations),
        ];
    }
}
