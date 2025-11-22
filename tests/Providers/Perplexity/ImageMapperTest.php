<?php

declare(strict_types=1);

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Perplexity\Maps\ImageMapper;
use Prism\Prism\ValueObjects\Media\Image;

it('maps image URL to payload', function (): void {
    $imageUrl = 'https://example.com/image.jpg';
    $media = new Image(url: $imageUrl);
    $mapper = new ImageMapper(media: $media);

    $payload = $mapper->toPayload();

    expect($payload)->toBe([
        'type' => 'image_url',
        'image_url' => [
            'url' => $imageUrl,
        ],
    ]);
});

it('maps image base64 to payload', function (): void {
    $base64Data = base64_encode('fake-image-content');
    $mimeType = 'image/jpeg';
    $media = new Image(base64: $base64Data, mimeType: $mimeType);
    $mapper = new ImageMapper(media: $media);

    $payload = $mapper->toPayload();

    expect($payload)->toBe([
        'type' => 'image_url',
        'image_url' => [
            'url' => "data:{$mimeType};base64,{$base64Data}",
        ],
    ]);
});

it('map files with the allowed mime types', function (string $mimeType): void {
    $base64Data = base64_encode('fake-image-content');
    $media = new Image(base64: $base64Data, mimeType: $mimeType);
    $mapper = new ImageMapper(media: $media);

    expect($mapper->toPayload())->toBeArray();
})->with(ImageMapper::SUPPORTED_MIME_TYPES);

it('doesnt map image with unsupported mime type', function (): void {
    $base64Data = base64_encode('fake-image-content');
    $media = new Image(base64: $base64Data, mimeType: 'image/bmp');
    new ImageMapper(media: $media);
})->throws(PrismException::class);

it("doesn't map image with invalid media", function (): void {
    $media = new Image;
    new ImageMapper(media: $media);
})->throws(PrismException::class);
