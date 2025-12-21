<?php

declare(strict_types=1);

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenRouter\Maps\DocumentMapper;
use Prism\Prism\ValueObjects\Media\Document;

it('maps documents from base64', function (): void {
    $document = Document::fromBase64(base64_encode('pdf-content'), 'application/pdf', 'test.pdf');

    $payload = (new DocumentMapper($document))->toPayload();

    expect($payload)->toBe([
        'type' => 'file',
        'file' => [
            'filename' => 'test.pdf',
            'file_data' => 'data:application/pdf;base64,'.base64_encode('pdf-content'),
        ],
    ]);
});

it('maps documents from url', function (): void {
    $document = Document::fromUrl('https://example.com/test.pdf', 'test.pdf');

    $payload = (new DocumentMapper($document))->toPayload();

    expect($payload)->toBe([
        'type' => 'file',
        'file' => [
            'filename' => 'test.pdf',
            'file_data' => 'https://example.com/test.pdf',
        ],
    ]);
});

it('uses default filename when document title is not provided', function (): void {
    $document = Document::fromBase64(base64_encode('content'), 'application/pdf');

    $payload = (new DocumentMapper($document))->toPayload();

    expect($payload['file']['filename'])->toBe('document');
});

it('maps documents from local path', function (): void {
    $document = Document::fromLocalPath('tests/Fixtures/test-pdf.pdf', 'Invoice.pdf');

    $payload = (new DocumentMapper($document))->toPayload();

    expect($payload['type'])->toBe('file');
    expect($payload['file']['filename'])->toBe('Invoice.pdf');
    expect($payload['file']['file_data'])->toStartWith('data:application/pdf;base64,');
    expect($payload['file']['file_data'])->toContain(base64_encode(file_get_contents('tests/Fixtures/test-pdf.pdf')));
});

it('throws exception for chunks (anthropic-specific)', function (): void {
    $document = Document::fromChunks(['chunk1', 'chunk2'], 'Chunked Document');

    new DocumentMapper($document);
})->throws(PrismException::class);

it('throws exception for file ids (not supported by openrouter)', function (): void {
    $document = Document::fromFileId('file_12345');

    new DocumentMapper($document);
})->throws(PrismException::class);
