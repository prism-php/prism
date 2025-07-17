<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\ValueObjects\Media\Media;

describe('creation', function (): void {
    it('can create from a local path', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/dimond.png');

        expect($media->localPath())->toBe('tests/Fixtures/dimond.png');
        expect($media->mimeType())->toBe('image/png');
    });

    it('can create from a storage path', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/dimond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->storagePath())->toBe('images/test-image.png');
        expect($media->mimeType())->toBe('image/png');
    });

    it('can create from a URL', function (): void {
        $media = Media::fromUrl('https://prismphp.com/storage/dimond.png');

        expect($media->url())->toBe('https://prismphp.com/storage/dimond.png');
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
        $media = Media::fromLocalPath('tests/Fixtures/dimond.png');

        expect($media->isFile())->toBeTrue();
    });

    test('isFile returns true for storage path', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/dimond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->isFile())->toBeTrue();
    });

    test('isFile returns false for url', function (): void {
        $media = Media::fromUrl('https://prismphp.com/storage/dimond.png');

        expect($media->isFile())->toBeFalse();
    });

    test('isUrl returns true for URL', function (): void {
        $media = Media::fromUrl('https://prismphp.com/storage/dimond.png');

        expect($media->isUrl())->toBeTrue();
    });

    test('hasRawContent returns true for local path', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/dimond.png');

        expect($media->hasRawContent())->toBeTrue();
    });

    test('hasRawContent returns true for storage path', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/dimond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->hasRawContent())->toBeTrue();
    });

    test('hasRawContent returns true for url', function (): void {
        $media = Media::fromUrl('https://prismphp.com/storage/dimond.png');

        expect($media->hasRawContent())->toBeTrue();
    });

    test('hasBase64 returns true for local path', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/dimond.png');

        expect($media->hasBase64())->toBeTrue();
    });

    test('hasBase64 returns true for storage path', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/dimond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->hasBase64())->toBeTrue();
    });

    test('hasBase64 returns true for url', function (): void {
        $media = Media::fromUrl('https://prismphp.com/storage/dimond.png');

        expect($media->hasBase64())->toBeTrue();
    });
});

describe('conversion', function (): void {
    it('converts local path to rawContent', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/dimond.png');

        expect($media->rawContent())->toBe(file_get_contents('tests/Fixtures/dimond.png'));
    });

    it('converts storage path to rawContent', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/dimond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->rawContent())->toBe(file_get_contents('tests/Fixtures/dimond.png'));
    });

    it('converts url to rawContent', function (): void {
        Http::fake([
            'https://prismphp.com/storage/dimond.png' => Http::sequence()
                ->push(file_get_contents('tests/Fixtures/dimond.png'))
                ->push(file_get_contents('tests/Fixtures/dimond.png')),
        ])->preventStrayRequests();

        $media = Media::fromUrl('https://prismphp.com/storage/dimond.png');

        expect($media->rawContent())->toBe(file_get_contents('tests/Fixtures/dimond.png'));
    });

    it('converts base64 to rawContent', function (): void {
        $media = Media::fromBase64(base64_encode('content'), 'text/plain');

        expect($media->rawContent())->toBe('content');
    });

    it('converts rawContent to base64', function (): void {
        $media = Media::fromRawContent('content', 'text/plain');

        expect($media->base64())->toBe(base64_encode('content'));
    });
});
