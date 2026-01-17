<?php

declare(strict_types=1);

use Prism\Prism\Providers\OpenRouter\Maps\VideoMapper;
use Prism\Prism\ValueObjects\Media\Video;

it('maps videos from base64 to data URL format', function (): void {
    $video = Video::fromBase64(base64_encode('video-content'), 'video/mp4');

    $payload = (new VideoMapper($video))->toPayload();

    expect($payload)->toBe([
        'type' => 'video_url',
        'video_url' => [
            'url' => 'data:video/mp4;base64,'.base64_encode('video-content'),
        ],
    ]);
});

it('maps videos from raw content to data URL format', function (): void {
    $video = Video::fromRawContent('video-content', 'video/webm');

    $payload = (new VideoMapper($video))->toPayload();

    expect($payload)->toBe([
        'type' => 'video_url',
        'video_url' => [
            'url' => 'data:video/webm;base64,'.base64_encode('video-content'),
        ],
    ]);
});

it('maps videos from URL directly', function (): void {
    $video = Video::fromUrl('https://example.com/video.mp4');

    $payload = (new VideoMapper($video))->toPayload();

    expect($payload)->toBe([
        'type' => 'video_url',
        'video_url' => [
            'url' => 'https://example.com/video.mp4',
        ],
    ]);
});

it('maps YouTube URLs directly', function (): void {
    $video = Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    $payload = (new VideoMapper($video))->toPayload();

    expect($payload)->toBe([
        'type' => 'video_url',
        'video_url' => [
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ],
    ]);
});

it('maps videos from local path', function (): void {
    $video = Video::fromLocalPath('tests/Fixtures/sample-video.mp4');

    $payload = (new VideoMapper($video))->toPayload();

    expect($payload['type'])->toBe('video_url');
    expect($payload['video_url']['url'])->toStartWith('data:video/mp4;base64,');
    expect($payload['video_url']['url'])->toContain(base64_encode(file_get_contents('tests/Fixtures/sample-video.mp4')));
});
