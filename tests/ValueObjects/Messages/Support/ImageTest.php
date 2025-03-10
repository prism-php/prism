<?php

declare(strict_types=1);

namespace Tests\ValueOpjects\Messages\Support;

use Prism\Prism\ValueObjects\Messages\Support\Image;

it('can create an image from a file', function (): void {
    $image = Image::fromPath('tests/Fixtures/test-image.png');

    expect($image->image)->toBe(base64_encode(file_get_contents('tests/Fixtures/test-image.png')));
    expect($image->mimeType)->toBe('image/png');
});

it('can create an image from a url', function (): void {
    $image = Image::fromUrl('https://storage.echolabs.dev/assets/logo.png');

    expect($image->image)->toBe('https://storage.echolabs.dev/assets/logo.png');
    expect($image->mimeType)->toBeNull();
});

it('can create an image from base64', function (): void {
    $image = Image::fromBase64(base64_encode(file_get_contents('tests/Fixtures/test-image.png')), 'image/png');

    expect($image->image)->toBe(base64_encode(file_get_contents('tests/Fixtures/test-image.png')));
    expect($image->mimeType)->toBe('image/png');
});

it('can create an image from base64 with mimetype', function (): void {
    $image = Image::fromBase64(base64_encode(file_get_contents('tests/Fixtures/test-image.png')), 'image/png');

    expect($image->image)->toBe(base64_encode(file_get_contents('tests/Fixtures/test-image.png')));
    expect($image->mimeType)->toBe('image/png');
});
