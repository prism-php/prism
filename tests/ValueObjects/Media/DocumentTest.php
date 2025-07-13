<?php

use Prism\Prism\ValueObjects\Media\Document;

it('can create a document from chunks', function (): void {
    $document = Document::fromChunks(['chunk1', 'chunk2'], 'title');

    expect($document->chunks())->toBe(['chunk1', 'chunk2']);
    expect($document->documentTitle())->toBe('title');
});
