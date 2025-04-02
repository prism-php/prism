<?php

use Illuminate\Support\Facades\Http;
use Prism\Prism\ValueObjects\Messages\Support\Document;

it('can create a document from chunks', function (): void {
    $document = Document::fromChunks(['chunk1', 'chunk2'], 'title', 'context');

    expect($document->document)->toBe(['chunk1', 'chunk2']);
    expect($document->mimeType)->toBeNull();
    expect($document->dataFormat)->toBe('content');
    expect($document->documentTitle)->toBe('title');
    expect($document->documentContext)->toBe('context');
});

it('can create a document from url', function (): void {
    Http::preventStrayRequests();

    Http::fake([
        // Stub a JSON response for GitHub endpoints...
        'example.com/*' => Http::response('test body', 200, [
            'Content-Type' => 'text/html',
        ]),
    ]);
    $document = Document::fromUrl('https://example.com/', 'title', 'context');

    expect($document->document)->not->toBeEmpty();
    expect($document->mimeType)->toBe('text/html');
    expect($document->dataFormat)->toBe('url');
    expect($document->documentTitle)->toBe('title');
    expect($document->documentContext)->toBe('context');
});
