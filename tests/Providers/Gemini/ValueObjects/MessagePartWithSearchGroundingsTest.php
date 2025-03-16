<?php

declare(strict_types=1);

use Prism\Prism\Providers\Gemini\ValueObjects\MessagePartWithSearchGroundings;
use Prism\Prism\Providers\Gemini\ValueObjects\SearchGrounding;

it('correctly casts to array', function (): void {
    $valueObject = new MessagePartWithSearchGroundings(
        text: 'text',
        startIndex: 0,
        endIndex: 1,
        groundings: [
            new SearchGrounding(
                title: 'title',
                uri: 'uri',
                confidence: 0.5
            ),
            new SearchGrounding(
                title: 'title2',
                uri: 'uri2',
                confidence: 0.6
            ),
        ]
    );

    expect($valueObject->toArray())->toBe([
        'text' => 'text',
        'startIndex' => 0,
        'endIndex' => 1,
        'groundings' => [
            [
                'title' => 'title',
                'uri' => 'uri',
                'confidence' => 0.5,
            ],
            [
                'title' => 'title2',
                'uri' => 'uri2',
                'confidence' => 0.6,
            ],
        ],
    ]);
});
