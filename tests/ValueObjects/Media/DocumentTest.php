<?php

use Prism\Prism\ValueObjects\Media\Document;

it('can create a document from chunks', function (): void {
    $document = Document::fromChunks(['chunk1', 'chunk2'], 'title');

    expect($document->chunks())->toBe(['chunk1', 'chunk2']);
    expect($document->documentTitle())->toBe('title');
});

it('can create a document from a file ID', function (): void {
    $document = Document::fromFileId('file-id', 'title');

    expect($document->fileId())->toBe('file-id');
    expect($document->documentTitle())->toBe('title');
});
