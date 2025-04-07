<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Prism\Prism\Providers\Anthropic\Maps\CitationMap;
use Prism\Prism\Providers\Anthropic\ValueObjects\Citation as AnthropicCitation;
use Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations as AnthropicMessagePartWithCitations;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Tests\TestCase;

class CitationMapTest extends TestCase
{
    public function test_map_citation(): void
    {
        $anthropicCitation = new AnthropicCitation(
            type: 'page_location',
            citedText: 'The grass is green.',
            startIndex: 1,
            endIndex: 2,
            documentIndex: 0,
            documentTitle: 'All about the grass and the sky'
        );

        $citation = CitationMap::mapCitation($anthropicCitation);

        $this->assertInstanceOf(Citation::class, $citation);
        $this->assertEquals('The grass is green.', $citation->text);
        $this->assertEquals(1, $citation->startPosition);
        $this->assertEquals(2, $citation->endPosition);
        $this->assertEquals(0, $citation->sourceIndex);
        $this->assertEquals('All about the grass and the sky', $citation->sourceTitle);
        $this->assertEquals('page_location', $citation->type);
    }

    public function test_map_message_part(): void
    {
        $anthropicCitation = new AnthropicCitation(
            type: 'page_location',
            citedText: 'The grass is green.',
            startIndex: 1,
            endIndex: 2,
            documentIndex: 0,
            documentTitle: 'All about the grass and the sky'
        );

        $anthropicMessagePart = new AnthropicMessagePartWithCitations(
            text: 'The grass is green.',
            citations: [$anthropicCitation]
        );

        $messagePart = CitationMap::mapMessagePart($anthropicMessagePart);

        $this->assertInstanceOf(MessagePartWithCitations::class, $messagePart);
        $this->assertEquals('The grass is green.', $messagePart->text);
        $this->assertCount(1, $messagePart->citations);
        $this->assertInstanceOf(Citation::class, $messagePart->citations[0]);
    }

    public function test_from_content_block(): void
    {
        $contentBlock = [
            'type' => 'text',
            'text' => 'The grass is green.',
            'citations' => [
                [
                    'type' => 'page_location',
                    'cited_text' => 'The grass is green.',
                    'document_index' => 0,
                    'document_title' => 'All about the grass and the sky',
                    'start_page_number' => 1,
                    'end_page_number' => 2,
                ],
            ],
        ];

        $messagePart = CitationMap::fromContentBlock($contentBlock);

        $this->assertInstanceOf(MessagePartWithCitations::class, $messagePart);
        $this->assertEquals('The grass is green.', $messagePart->text);
        $this->assertCount(1, $messagePart->citations);
        $this->assertInstanceOf(Citation::class, $messagePart->citations[0]);
        $this->assertEquals('The grass is green.', $messagePart->citations[0]->text);
        $this->assertEquals('All about the grass and the sky', $messagePart->citations[0]->sourceTitle);
    }
}
