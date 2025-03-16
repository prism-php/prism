<?php

declare(strict_types=1);

use Prism\Prism\Providers\Gemini\ValueObjects\SearchGrounding;

it('correctly casts to array', function (): void {
    $valueObject = new SearchGrounding(
        title: 'title',
        uri: 'uri',
        confidence: 0.5
    );

    expect($valueObject->toArray())->toBe([
        'title' => 'title',
        'uri' => 'uri',
        'confidence' => 0.5,
    ]);
});
