<?php

use Prism\Prism\ValueObjects\Messages\Support\Document;

it('can create a document from chunks', function (): void {
    $document = Document::fromChunks(['chunk1', 'chunk2'], 'title');

    expect($document->chunks())->toBe(['chunk1', 'chunk2']);
    expect($document->documentTitle())->toBe('title');
});

it('can convert chunks to rawContent', function (): void {
    $document = Document::fromChunks(['chunk1', 'chunk2'], 'title');

    expect($document->rawContent())->toBe(implode("\n", ['chunk1', 'chunk2']));
    expect($document->documentTitle())->toBe('title');
});
