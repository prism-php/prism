<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Prism\Prism\Providers\Gemini\Maps\CitationMap;
use Prism\Prism\Providers\Gemini\ValueObjects\MessagePartWithSearchGroundings;
use Prism\Prism\Providers\Gemini\ValueObjects\SearchGrounding;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Tests\TestCase;

class CitationMapTest extends TestCase
{
    public function test_map_grounding(): void
    {
        $searchGrounding = new SearchGrounding(
            title: 'Google Search',
            uri: 'https://google.com',
            confidence: 0.95
        );

        $citation = CitationMap::mapGrounding($searchGrounding);

        $this->assertInstanceOf(Citation::class, $citation);
        $this->assertEquals('', $citation->text);
        $this->assertEquals(0, $citation->startPosition);
        $this->assertEquals(0, $citation->endPosition);
        $this->assertEquals(0, $citation->sourceIndex);
        $this->assertEquals('Google Search', $citation->sourceTitle);
        $this->assertEquals('https://google.com', $citation->sourceUrl);
        $this->assertEquals(0.95, $citation->confidence);
        $this->assertEquals('url', $citation->type);
    }

    public function test_map_message_part(): void
    {
        $searchGrounding = new SearchGrounding(
            title: 'Google Search',
            uri: 'https://google.com',
            confidence: 0.95
        );

        $geminiPart = new MessagePartWithSearchGroundings(
            text: 'The price of Google stock is',
            startIndex: 0,
            endIndex: 26,
            groundings: [$searchGrounding]
        );

        $messagePart = CitationMap::mapMessagePart($geminiPart);

        $this->assertInstanceOf(MessagePartWithCitations::class, $messagePart);
        $this->assertEquals('The price of Google stock is', $messagePart->text);
        $this->assertEquals(0, $messagePart->startPosition);
        $this->assertEquals(26, $messagePart->endPosition);
        $this->assertCount(1, $messagePart->citations);
        $this->assertInstanceOf(Citation::class, $messagePart->citations[0]);
        $this->assertEquals('Google Search', $messagePart->citations[0]->sourceTitle);
    }

    public function test_map_groundings(): void
    {
        $groundingSupports = [
            [
                'segment' => [
                    'startIndex' => 0,
                    'endIndex' => 26,
                    'text' => 'The price of Google stock is',
                ],
                'groundingChunkIndices' => [0],
                'confidenceScores' => [0.95],
            ],
        ];

        $groundingChunks = [
            [
                'web' => [
                    'uri' => 'https://google.com',
                    'title' => 'Google Search',
                ],
            ],
        ];

        $messageParts = CitationMap::mapGroundings($groundingSupports, $groundingChunks);

        $this->assertIsArray($messageParts);
        $this->assertCount(1, $messageParts);
        $this->assertInstanceOf(MessagePartWithCitations::class, $messageParts[0]);
        $this->assertEquals('The price of Google stock is', $messageParts[0]->text);
        $this->assertEquals(0, $messageParts[0]->startPosition);
        $this->assertEquals(26, $messageParts[0]->endPosition);
    }
}
