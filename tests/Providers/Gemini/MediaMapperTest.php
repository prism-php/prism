<?php

declare(strict_types=1);

use Prism\Prism\Providers\Gemini\Maps\AudioVideoMapper;
use Prism\Prism\Providers\Gemini\Maps\DocumentMapper;
use Prism\Prism\Providers\Gemini\Maps\ImageMapper;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Video;

describe('ImageMapper', function (): void {
    it('maps images from base64', function (): void {
        $image = Image::fromBase64(base64_encode('image-content'), 'image/png');

        $payload = (new ImageMapper($image))->toPayload();

        expect($payload)->toBe([
            'inline_data' => [
                'mime_type' => 'image/png',
                'data' => base64_encode('image-content'),
            ],
        ]);
    });

    it('includes media_resolution when provider option is set', function (): void {
        $image = Image::fromBase64(base64_encode('image-content'), 'image/png')
            ->withProviderOptions(['mediaResolution' => 'MEDIA_RESOLUTION_HIGH']);

        $payload = (new ImageMapper($image))->toPayload();

        expect($payload)->toBe([
            'inline_data' => [
                'mime_type' => 'image/png',
                'data' => base64_encode('image-content'),
            ],
            'media_resolution' => [
                'level' => 'MEDIA_RESOLUTION_HIGH',
            ],
        ]);
    });

    it('does not include media_resolution when provider option is not set', function (): void {
        $image = Image::fromBase64(base64_encode('image-content'), 'image/png');

        $payload = (new ImageMapper($image))->toPayload();

        expect($payload)->not->toHaveKey('media_resolution');
    });
});

describe('DocumentMapper', function (): void {
    it('maps documents from base64', function (): void {
        $document = Document::fromBase64(base64_encode('pdf-content'), 'application/pdf', 'test.pdf');

        $payload = (new DocumentMapper($document))->toPayload();

        expect($payload)->toBe([
            'inline_data' => [
                'mime_type' => 'application/pdf',
                'data' => base64_encode('pdf-content'),
            ],
        ]);
    });

    it('includes media_resolution when provider option is set', function (): void {
        $document = Document::fromBase64(base64_encode('pdf-content'), 'application/pdf', 'test.pdf')
            ->withProviderOptions(['mediaResolution' => 'MEDIA_RESOLUTION_MEDIUM']);

        $payload = (new DocumentMapper($document))->toPayload();

        expect($payload)->toBe([
            'inline_data' => [
                'mime_type' => 'application/pdf',
                'data' => base64_encode('pdf-content'),
            ],
            'media_resolution' => [
                'level' => 'MEDIA_RESOLUTION_MEDIUM',
            ],
        ]);
    });
});

describe('AudioVideoMapper', function (): void {
    it('maps video from base64', function (): void {
        $video = Video::fromBase64(base64_encode('video-content'), 'video/mp4');

        $payload = (new AudioVideoMapper($video))->toPayload();

        expect($payload)->toBe([
            'inline_data' => [
                'mime_type' => 'video/mp4',
                'data' => base64_encode('video-content'),
            ],
        ]);
    });

    it('maps audio from base64', function (): void {
        $audio = Audio::fromBase64(base64_encode('audio-content'), 'audio/wav');

        $payload = (new AudioVideoMapper($audio))->toPayload();

        expect($payload)->toBe([
            'inline_data' => [
                'mime_type' => 'audio/wav',
                'data' => base64_encode('audio-content'),
            ],
        ]);
    });

    it('includes media_resolution when provider option is set', function (): void {
        $video = Video::fromBase64(base64_encode('video-content'), 'video/mp4')
            ->withProviderOptions(['mediaResolution' => 'MEDIA_RESOLUTION_LOW']);

        $payload = (new AudioVideoMapper($video))->toPayload();

        expect($payload)->toBe([
            'inline_data' => [
                'mime_type' => 'video/mp4',
                'data' => base64_encode('video-content'),
            ],
            'media_resolution' => [
                'level' => 'MEDIA_RESOLUTION_LOW',
            ],
        ]);
    });
});
