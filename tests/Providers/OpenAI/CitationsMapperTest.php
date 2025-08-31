<?php

declare(strict_types=1);

use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\Providers\OpenAI\Maps\CitationsMapper;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

it('maps OpenAI citations to Prism format', function (): void {
    $contentBlock = [
        'type' => 'output_text',
        'text' => 'On March 6, 2025, several news sources reported...',
        'annotations' => [
            [
                'type' => 'url_citation',
                'start_index' => 0,
                'end_index' => 45,
                'url' => 'https://example.com/news',
                'title' => 'Example News Article',
            ],
        ],
    ];

    $messagePartWithCitations = CitationsMapper::mapFromOpenAI($contentBlock);

    expect($messagePartWithCitations)->toBeInstanceOf(MessagePartWithCitations::class);
    expect($messagePartWithCitations->outputText)->toBe('On March 6, 2025, several news sources reported...');
    expect($messagePartWithCitations->citations)->toHaveCount(1);

    $citation = $messagePartWithCitations->citations[0];

    expect($citation)->toBeInstanceOf(Citation::class);
    expect($citation->sourceType)->toBe(CitationSourceType::Url);
    expect($citation->source)->toBe('https://example.com/news');
    expect($citation->sourceTitle)->toBe('Example News Article');
});

it('handles content without annotations', function (): void {
    $contentBlock = [
        'type' => 'output_text',
        'text' => 'Simple text without citations',
    ];

    $messagePartWithCitations = CitationsMapper::mapFromOpenAI($contentBlock);

    expect($messagePartWithCitations)->toBeInstanceOf(MessagePartWithCitations::class);
    expect($messagePartWithCitations->outputText)->toBe('Simple text without citations');
    expect($messagePartWithCitations->citations)->toHaveCount(0);
});

it('handles content block with multiple citations', function (): void {
    $contentBlock = [
        'type' => 'output_text',
        'text' => 'First part with citation',
        'annotations' => [
            [
                'type' => 'url_citation',
                'start_index' => 0,
                'end_index' => 10,
                'url' => 'https://example.com/first',
                'title' => 'First Source',
            ],
            [
                'type' => 'url_citation',
                'start_index' => 11,
                'end_index' => 25,
                'url' => 'https://example.com/second',
                'title' => 'Second Source',
            ],
        ],
    ];

    $messagePartsWithCitations = CitationsMapper::mapFromOpenAI($contentBlock);

    expect($messagePartsWithCitations)->not()->toBeNull();
    expect($messagePartsWithCitations->citations)->toHaveCount(2);
});

it('maps back to OpenAI format', function (): void {
    $originalData = [
        'type' => 'output_text',
        'text' => 'On March 6, 2025, several news sources reported...',
        'annotations' => [
            [
                'type' => 'url_citation',
                'start_index' => 0,
                'end_index' => 45,
                'url' => 'https://example.com/news',
                'title' => 'Example News Article',
            ],
        ],
    ];

    $messagePartWithCitations = CitationsMapper::mapFromOpenAI($originalData);
    $roundTripResult = CitationsMapper::mapToOpenAI($messagePartWithCitations);

    expect($roundTripResult)->toEqual($originalData);
});
