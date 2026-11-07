<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;

describe('creation', function (): void {
    it('can create from file ID', function (): void {
        $media = Media::fromFileId('file-id');

        expect($media->fileId())->toBe('file-id');
    });

    it('can create from a local path', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/diamond.png');

        expect($media->localPath())->toBe('tests/Fixtures/diamond.png');
        expect($media->mimeType())->toBe('image/png');
    });

    it('can create from a storage path', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/diamond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->storagePath())->toBe('images/test-image.png');
        expect($media->mimeType())->toBe('image/png');
    });

    it('can create from a URL', function (): void {
        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->url())->toBe('https://prismphp.com/storage/diamond.png');
    });

    it('can create from raw content', function (): void {
        $media = Media::fromRawContent('raw-content', 'text/plain');

        expect($media->rawContent())->toBe('raw-content');
        expect($media->mimeType())->toBe('text/plain');
    });

    it('can create from base64', function (): void {
        $media = Media::fromBase64(base64_encode('content'), 'text/plain');

        expect($media->base64())->toBe(base64_encode('content'));
        expect($media->mimeType())->toBe('text/plain');
    });
});

describe('inspection', function (): void {
    test('isFile returns true for local path', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/diamond.png');

        expect($media->isFile())->toBeTrue();
    });

    test('isFile returns true for storage path', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/diamond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->isFile())->toBeTrue();
    });

    test('isFile returns false for url', function (): void {
        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->isFile())->toBeFalse();
    });

    test('isUrl returns true for URL', function (): void {
        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->isUrl())->toBeTrue();
    });

    test('hasRawContent returns true for local path', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/diamond.png');

        expect($media->hasRawContent())->toBeTrue();
    });

    test('hasRawContent returns true for storage path', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/diamond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->hasRawContent())->toBeTrue();
    });

    test('hasRawContent returns false for url', function (): void {
        // A URL on its own has no bytes this object can produce without a
        // network request, and reading media no longer makes one.
        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->hasRawContent())->toBeFalse();
    });

    test('hasBase64 returns true for local path', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/diamond.png');

        expect($media->hasBase64())->toBeTrue();
    });

    test('hasBase64 returns true for storage path', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/diamond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->hasBase64())->toBeTrue();
    });

    test('hasBase64 returns false for url', function (): void {
        // hasBase64() answers whether the bytes are already in hand, which a
        // URL that was never fetched cannot claim. A guard built on it ("will
        // sending this media make an outbound request?") relies on that.
        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->hasBase64())->toBeFalse();
    });
});

describe('conversion', function (): void {
    it('converts local path to rawContent', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/diamond.png');

        expect($media->rawContent())->toBe(file_get_contents('tests/Fixtures/diamond.png'));
    });

    it('converts storage path to rawContent', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/diamond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->rawContent())->toBe(file_get_contents('tests/Fixtures/diamond.png'));
    });

    it('does not fetch a url to produce rawContent', function (): void {
        Http::fake(['*' => Http::response(file_get_contents('tests/Fixtures/diamond.png'))]);

        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->rawContent())->toBeNull();
        Http::assertSentCount(0);
    });

    it('resolves a url only when fetchUrlContent() is called explicitly', function (): void {
        // Also the positive control for every "sent 0 requests" assertion in
        // this file: if Http::fake were not intercepting, this would not record
        // exactly one request, and those zeros would mean nothing.
        Http::fake(['*' => Http::response(file_get_contents('tests/Fixtures/diamond.png'))]);

        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png')->fetchUrlContent();

        Http::assertSentCount(1);
        expect($media->rawContent())->toBe(file_get_contents('tests/Fixtures/diamond.png'))
            ->and($media->hasRawContent())->toBeTrue()
            ->and($media->hasBase64())->toBeTrue();
    });

    it('makes no request when anything reads a url media pointed at cloud metadata', function (): void {
        // Every reader is exercised: the implicit fetch had several doors
        // (rawContent(), base64() through it, mimeType() sniffing through it,
        // resource()), and closing some would leave the rest.
        Http::fake(['*' => Http::response('metadata')]);

        $media = Image::fromUrl('http://169.254.169.254/latest/meta-data/iam/');

        $media->toArray();
        $media->hasRawContent();
        $media->hasBase64();
        expect($media->rawContent())->toBeNull()
            ->and($media->base64())->toBeNull()
            ->and($media->mimeType())->toBeNull();

        try {
            $media->resource();
        } catch (InvalidArgumentException) {
            // refused, as asserted below
        }

        Http::assertSentCount(0);
    });

    it('refuses to make a resource from a url, and names the explicit fetch', function (): void {
        // Audio handlers and image edits reach resource() on ordinary calls, so
        // this is where an upgrade surfaces: the message says what to call.
        Http::fake(['*' => Http::response(file_get_contents('tests/Fixtures/diamond.png'))]);

        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect(fn () => $media->resource())->toThrow(InvalidArgumentException::class, 'fetchUrlContent()');
        Http::assertSentCount(0);
    });

    it('keeps credentials out of a failed fetch message', function (string $url, string $expected): void {
        Http::fake(['*' => Http::response('')]);

        expect(fn (): Media => Media::fromUrl($url)->fetchUrlContent())
            ->toThrow(InvalidArgumentException::class, $expected.' returns no content.');
    })->with([
        'plain' => ['https://files.example.com/a/report.pdf', 'https://files.example.com/a/report.pdf'],
        'userinfo' => ['https://alice:hunter2@files.example.com/a', 'https://files.example.com/a'],
        'query' => ['https://files.example.com/a?token=SECRET123', 'https://files.example.com/a?[redacted]'],
        'fragment' => ['https://files.example.com/a#part', 'https://files.example.com/a'],
        'port kept' => ['http://localhost:9000/bucket/key', 'http://localhost:9000/bucket/key'],
        'no host' => ['file:///etc/passwd', '[url without a host]'],
    ]);

    it('converts base64 to rawContent', function (): void {
        $media = Media::fromBase64(base64_encode('content'), 'text/plain');

        expect($media->rawContent())->toBe('content');
    });

    it('converts rawContent to base64', function (): void {
        $media = Media::fromRawContent('content', 'text/plain');

        expect($media->base64())->toBe(base64_encode('content'));
    });
});
