<?php

declare(strict_types=1);

namespace Tests\ValueObjects;

use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Tests\TestCase;

class CitationTest extends TestCase
{
    public function test_it_can_create_citation_value_object(): void
    {
        $citation = new Citation(
            text: 'The grass is green.',
            startPosition: 0,
            endPosition: 20,
            sourceIndex: 0,
            sourceTitle: 'Nature facts',
            sourceUrl: 'https://example.com/nature',
            confidence: 0.97,
            type: 'page'
        );

        $this->assertEquals('The grass is green.', $citation->text);
        $this->assertEquals(0, $citation->startPosition);
        $this->assertEquals(20, $citation->endPosition);
        $this->assertEquals(0, $citation->sourceIndex);
        $this->assertEquals('Nature facts', $citation->sourceTitle);
        $this->assertEquals('https://example.com/nature', $citation->sourceUrl);
        $this->assertEquals(0.97, $citation->confidence);
        $this->assertEquals('page', $citation->type);
    }

    public function test_it_can_convert_to_array(): void
    {
        $citation = new Citation(
            text: 'The grass is green.',
            startPosition: 0,
            endPosition: 20,
            sourceIndex: 0,
            sourceTitle: 'Nature facts',
            sourceUrl: 'https://example.com/nature',
            confidence: 0.97,
            type: 'page'
        );

        $array = $citation->toArray();

        $this->assertEquals([
            'text' => 'The grass is green.',
            'startPosition' => 0,
            'endPosition' => 20,
            'sourceIndex' => 0,
            'sourceTitle' => 'Nature facts',
            'sourceUrl' => 'https://example.com/nature',
            'confidence' => 0.97,
            'type' => 'page',
        ], $array);
    }

    public function test_it_filters_null_values_when_converting_to_array(): void
    {
        $citation = new Citation(
            text: 'The grass is green.',
            startPosition: 0,
            endPosition: 20,
            sourceIndex: 0
        );

        $array = $citation->toArray();

        $this->assertEquals([
            'text' => 'The grass is green.',
            'startPosition' => 0,
            'endPosition' => 20,
            'sourceIndex' => 0,
        ], $array);
    }

    public function test_message_part_with_citations(): void
    {
        $citation1 = new Citation(
            text: 'The grass is green.',
            startPosition: 0,
            endPosition: 20,
            sourceIndex: 0
        );

        $citation2 = new Citation(
            text: 'The sky is blue.',
            startPosition: 21,
            endPosition: 37,
            sourceIndex: 0
        );

        $messagePart = new MessagePartWithCitations(
            text: 'The grass is green and the sky is blue.',
            citations: [$citation1, $citation2],
            startPosition: 0,
            endPosition: 38
        );

        $this->assertEquals('The grass is green and the sky is blue.', $messagePart->text);
        $this->assertCount(2, $messagePart->citations);
        $this->assertEquals(0, $messagePart->startPosition);
        $this->assertEquals(38, $messagePart->endPosition);

        $array = $messagePart->toArray();

        $this->assertEquals([
            'text' => 'The grass is green and the sky is blue.',
            'startPosition' => 0,
            'endPosition' => 38,
            'citations' => [
                [
                    'text' => 'The grass is green.',
                    'startPosition' => 0,
                    'endPosition' => 20,
                    'sourceIndex' => 0,
                ],
                [
                    'text' => 'The sky is blue.',
                    'startPosition' => 21,
                    'endPosition' => 37,
                    'sourceIndex' => 0,
                ],
            ],
        ], $array);
    }
}
