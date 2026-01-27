<?php

declare(strict_types=1);

use Prism\Prism\Enums\Citations\CitationSourcePositionType;
use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\Providers\Anthropic\Maps\CitationsMapper;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

describe('from anthropic payload', function (): void {
    it('can map content block with web search result citation', function (): void {
        $contentBlock = [
            'type' => 'text',
            'text' => 'Here is some text with a web citation.',
            'citations' => [
                [
                    'type' => 'web_search_result_location',
                    'cited_text' => 'cited content from web',
                    'title' => 'Web Page Title',
                    'url' => 'https://example.com/page',
                    'encrypted_index' => 'foobar',
                ],
            ],
        ];

        $result = CitationsMapper::mapFromAnthropic($contentBlock);

        expect($result)->toBeInstanceOf(MessagePartWithCitations::class);
        expect($result->outputText)->toBe('Here is some text with a web citation.');
        expect($result->citations)->toHaveCount(1);

        $citation = $result->citations[0];
        expect($citation)->toBeInstanceOf(Citation::class);
        expect($citation->sourceType)->toBe(CitationSourceType::Url);
        expect($citation->source)->toBe('https://example.com/page');
        expect($citation->sourceText)->toBe('cited content from web');
        expect($citation->sourceTitle)->toBe('Web Page Title');
        expect($citation->sourcePositionType)->toBeNull();
        expect($citation->sourceStartIndex)->toBeNull();
        expect($citation->sourceEndIndex)->toBeNull();
        expect($citation->additionalContent['encrypted_index'] ?? null)->toBe('foobar');
    });

    it('can map content block with page location citation', function (): void {
        $contentBlock = [
            'type' => 'text',
            'text' => 'Here is some text with a page citation.',
            'citations' => [
                [
                    'type' => 'page_location',
                    'cited_text' => 'cited content from page',
                    'document_title' => 'Document Title',
                    'document_index' => 1,
                    'start_page_number' => 5,
                    'end_page_number' => 7,
                ],
            ],
        ];

        $result = CitationsMapper::mapFromAnthropic($contentBlock);

        expect($result)->toBeInstanceOf(MessagePartWithCitations::class);
        expect($result->outputText)->toBe('Here is some text with a page citation.');
        expect($result->citations)->toHaveCount(1);

        $citation = $result->citations[0];
        expect($citation->sourceType)->toBe(CitationSourceType::Document);
        expect($citation->source)->toBe(1);
        expect($citation->sourceText)->toBe('cited content from page');
        expect($citation->sourceTitle)->toBe('Document Title');
        expect($citation->sourcePositionType)->toBe(CitationSourcePositionType::Page);
        expect($citation->sourceStartIndex)->toBe(5);
        expect($citation->sourceEndIndex)->toBe(7);
    });

    it('can map content block with character location citation', function (): void {
        $contentBlock = [
            'type' => 'text',
            'text' => 'Here is some text with a character citation.',
            'citations' => [
                [
                    'type' => 'char_location',
                    'cited_text' => 'cited content from character range',
                    'document_title' => 'Character Document',
                    'document_index' => 2,
                    'start_char_index' => 100,
                    'end_char_index' => 200,
                ],
            ],
        ];

        $result = CitationsMapper::mapFromAnthropic($contentBlock);

        $citation = $result->citations[0];
        expect($citation->sourceType)->toBe(CitationSourceType::Document);
        expect($citation->source)->toBe(2);
        expect($citation->sourceText)->toBe('cited content from character range');
        expect($citation->sourceTitle)->toBe('Character Document');
        expect($citation->sourcePositionType)->toBe(CitationSourcePositionType::Character);
        expect($citation->sourceStartIndex)->toBe(100);
        expect($citation->sourceEndIndex)->toBe(200);
    });

    it('can map content block with content block location citation', function (): void {
        $contentBlock = [
            'type' => 'text',
            'text' => 'Here is some text with a content block citation.',
            'citations' => [
                [
                    'type' => 'content_block_location',
                    'cited_text' => 'cited content from block',
                    'document_title' => 'Block Document',
                    'document_index' => 3,
                    'start_block_index' => 10,
                    'end_block_index' => 15,
                ],
            ],
        ];

        $result = CitationsMapper::mapFromAnthropic($contentBlock);

        $citation = $result->citations[0];
        expect($citation->sourceType)->toBe(CitationSourceType::Document);
        expect($citation->source)->toBe(3);
        expect($citation->sourceText)->toBe('cited content from block');
        expect($citation->sourceTitle)->toBe('Block Document');
        expect($citation->sourcePositionType)->toBe(CitationSourcePositionType::Chunk);
        expect($citation->sourceStartIndex)->toBe(10);
        expect($citation->sourceEndIndex)->toBe(15);
    });

    it('can map content block with multiple citations', function (): void {
        $contentBlock = [
            'type' => 'text',
            'text' => 'Text with multiple citations.',
            'citations' => [
                [
                    'type' => 'web_search_result_location',
                    'cited_text' => 'web citation',
                    'title' => 'Web Title',
                    'url' => 'https://example.com',
                ],
                [
                    'type' => 'page_location',
                    'cited_text' => 'page citation',
                    'document_title' => 'Doc Title',
                    'document_index' => 1,
                    'start_page_number' => 1,
                    'end_page_number' => 2,
                ],
            ],
        ];

        $result = CitationsMapper::mapFromAnthropic($contentBlock);

        expect($result->citations)->toHaveCount(2);
        expect($result->citations[0]->sourceType)->toBe(CitationSourceType::Url);
        expect($result->citations[1]->sourceType)->toBe(CitationSourceType::Document);
    });

    it('can map content block with no citations', function (): void {
        $contentBlock = [
            'type' => 'text',
            'text' => 'Text without citations.',
        ];

        $result = CitationsMapper::mapFromAnthropic($contentBlock);

        expect($result->outputText)->toBe('Text without citations.');
        expect($result->citations)->toHaveCount(0);
    });

    it('throws exception for unknown citation type', function (): void {
        $contentBlock = [
            'type' => 'text',
            'text' => 'Text with unknown citation type.',
            'citations' => [
                [
                    'type' => 'unknown_citation_type',
                    'cited_text' => 'some text',
                ],
            ],
        ];

        expect(fn (): MessagePartWithCitations => CitationsMapper::mapFromAnthropic($contentBlock))
            ->toThrow(InvalidArgumentException::class, 'Unknown citation type: unknown_citation_type');
    });

    it('falls back to title when document_title is not present', function (): void {
        $contentBlock = [
            'type' => 'text',
            'text' => 'Text with title fallback test.',
            'citations' => [
                [
                    'type' => 'web_search_result_location',
                    'cited_text' => 'some text',
                    'title' => 'Regular Title',
                    'url' => 'https://example.com',
                ],
            ],
        ];

        $result = CitationsMapper::mapFromAnthropic($contentBlock);

        expect($result->citations[0]->sourceTitle)->toBe('Regular Title');
    });
});

describe('to anthropic payload', function (): void {
    it('ensures round-trip compatibility for web search result citations', function (): void {
        $originalAnthropicData = [
            'type' => 'text',
            'text' => 'Here is some text with a web citation.',
            'citations' => [
                [
                    'type' => 'web_search_result_location',
                    'cited_text' => 'cited content from web',
                    'url' => 'https://example.com/page',
                    'title' => 'Web Page Title',
                    'encrypted_index' => 'foobar',
                ],
            ],
        ];

        $messagePartWithCitations = CitationsMapper::mapFromAnthropic($originalAnthropicData);
        $roundTripResult = CitationsMapper::mapToAnthropic($messagePartWithCitations);

        expect($roundTripResult)->toBe($originalAnthropicData);
    });

    it('ensures round-trip compatibility for page location citations', function (): void {
        $originalAnthropicData = [
            'type' => 'text',
            'text' => 'Here is some text with a page citation.',
            'citations' => [
                [
                    'type' => 'page_location',
                    'cited_text' => 'cited content from page',
                    'document_index' => 1,
                    'document_title' => 'Document Title',
                    'start_page_number' => 5,
                    'end_page_number' => 7,
                ],
            ],
        ];

        $messagePartWithCitations = CitationsMapper::mapFromAnthropic($originalAnthropicData);
        $roundTripResult = CitationsMapper::mapToAnthropic($messagePartWithCitations);

        expect($roundTripResult)->toBe($originalAnthropicData);
    });

    it('ensures round-trip compatibility for character location citations', function (): void {
        $originalAnthropicData = [
            'type' => 'text',
            'text' => 'Here is some text with a character citation.',
            'citations' => [
                [
                    'type' => 'char_location',
                    'cited_text' => 'cited content from character range',
                    'document_index' => 2,
                    'document_title' => 'Character Document',
                    'start_char_index' => 100,
                    'end_char_index' => 200,
                ],
            ],
        ];

        $messagePartWithCitations = CitationsMapper::mapFromAnthropic($originalAnthropicData);
        $roundTripResult = CitationsMapper::mapToAnthropic($messagePartWithCitations);

        expect($roundTripResult)->toBe($originalAnthropicData);
    });

    it('ensures round-trip compatibility for content block location citations', function (): void {
        $originalAnthropicData = [
            'type' => 'text',
            'text' => 'Here is some text with a content block citation.',
            'citations' => [
                [
                    'type' => 'content_block_location',
                    'cited_text' => 'cited content from block',
                    'document_index' => 3,
                    'document_title' => 'Block Document',
                    'start_block_index' => 10,
                    'end_block_index' => 15,
                ],
            ],
        ];

        $messagePartWithCitations = CitationsMapper::mapFromAnthropic($originalAnthropicData);
        $roundTripResult = CitationsMapper::mapToAnthropic($messagePartWithCitations);

        expect($roundTripResult)->toBe($originalAnthropicData);
    });

    it('ensures round-trip compatibility for multiple citations', function (): void {
        $originalAnthropicData = [
            'type' => 'text',
            'text' => 'Text with multiple citations from different sources.',
            'citations' => [
                [
                    'type' => 'web_search_result_location',
                    'cited_text' => 'web citation content',
                    'url' => 'https://example.com/web',
                    'title' => 'Web Source',
                ],
                [
                    'type' => 'page_location',
                    'cited_text' => 'document page content',
                    'document_index' => 1,
                    'document_title' => 'Document Title',
                    'start_page_number' => 3,
                    'end_page_number' => 5,
                ],
                [
                    'type' => 'char_location',
                    'cited_text' => 'character range content',
                    'document_index' => 2,
                    'document_title' => 'Character Doc',
                    'start_char_index' => 150,
                    'end_char_index' => 250,
                ],
            ],
        ];

        $messagePartWithCitations = CitationsMapper::mapFromAnthropic($originalAnthropicData);
        $roundTripResult = CitationsMapper::mapToAnthropic($messagePartWithCitations);

        expect($roundTripResult)->toBe($originalAnthropicData);
    });
});
