<?php

use Prism\Prism\ValueObjects\Messages\Support\Document;

it('can create a document from chunks', function (): void {
    $document = Document::fromChunks(['chunk1', 'chunk2'], 'title', 'context');

    expect($document->document)->toBe(['chunk1', 'chunk2']);
    expect($document->mimeType)->toBeNull();
    expect($document->dataFormat)->toBe('content');
    expect($document->documentTitle)->toBe('title');
    expect($document->documentContext)->toBe('context');
});
