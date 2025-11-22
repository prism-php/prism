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

it('throws exception when document has base64 content but no mime type', function (): void {
    $base64 = base64_encode('fake-document-content');

    $media = new Document(base64: $base64);
    new DocumentMapper(media: $media);
})->throws(PrismException::class);

it('throws exception for various unsupported mime types', function (string $mimeType): void {
    $base64 = base64_encode('fake-document-content');

    $media = new Document(base64: $base64, mimeType: $mimeType);
    new DocumentMapper(media: $media);
})->with([
    'image/jpeg',
    'image/png',
    'video/mp4',
    'audio/mpeg',
    'application/json',
    'application/zip',
    'application/x-tar',
    'text/html',
])->throws(PrismException::class);

it('successfully maps document with all supported mime types', function (string $mimeType): void {
    $base64 = base64_encode('fake-document-content');

    $media = new Document(base64: $base64, mimeType: $mimeType);
    $mapper = new DocumentMapper(media: $media);

    $payload = $mapper->toPayload();

    expect($payload)->toBeArray();
})->with(DocumentMapper::SUPPORTED_MIME_TYPES);

it('throws exception when document has mime type but no raw content', function (): void {
    $media = new Document(mimeType: 'application/pdf');
    new DocumentMapper(media: $media);
})->throws(PrismException::class);
