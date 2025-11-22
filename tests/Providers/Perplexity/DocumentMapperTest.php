<?php

declare(strict_types=1);

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Perplexity\Maps\DocumentMapper;
use Prism\Prism\ValueObjects\Media\Document;

it('maps document from url', function (): void {
    $url = 'https://example.com/document.pdf';

    $media = new Document(url: $url);
    $mapper = new DocumentMapper(media: $media);

    $this->assertEquals($mapper->toPayload(), [
        'type' => 'file_url',
        'file_url' => [
            'url' => $url,
        ],
    ]);
});

it('maps document from raw content', function (): void {
    $base64 = base64_encode('fake-document-content');
    $mimeType = 'application/pdf';

    $media = new Document(base64: $base64, mimeType: $mimeType);
    $mapper = new DocumentMapper(media: $media);

    $this->assertEquals([
        'type' => 'file_url',
        'file_url' => [
            'url' => "data:{$mimeType};base64,{$base64}",
        ],
    ], $mapper->toPayload());
});

it('includes the file name in the payload when it is available', function (): void {
    $url = 'https://example.com/document.pdf';

    $media = new Document(url: $url);
    $media->as('document.pdf');
    $mapper = new DocumentMapper(media: $media);

    $this->assertEquals($mapper->toPayload(), [
        'type' => 'file_url',
        'file_url' => [
            'url' => $url,
        ],
        'file_name' => 'document.pdf',
    ]);
});

it("doesn't map document with invalid media", function (): void {
    $media = new Document;
    new DocumentMapper(media: $media);
})->throws(PrismException::class);
