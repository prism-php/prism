<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Video;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', env('GEMINI_API_KEY', 'gk-1234'));
});

$testImageBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
$testAudioBase64 = 'SUQzAwAAAAAA';
$testVideoBase64 = 'AAAAIGZ0eXBpc29t';
$testPdfBase64 = 'JVBERi0xLjQKJcfs...';

it('returns embeddings from an image using gemini embedding 2 preview', function () use ($testImageBase64): void {
    FixtureResponse::fakeResponseSequence('models/gemini-embedding-2-preview:embedContent', 'gemini/multimodal-embeddings-image');

    $response = Prism::embeddings()
        ->using(Provider::Gemini, 'gemini-embedding-2-preview')
        ->fromImage(Image::fromBase64($testImageBase64, 'image/png'))
        ->asEmbeddings();

    expect($response->embeddings)->toHaveCount(1);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return str_contains($request->url(), 'models/gemini-embedding-2-preview:embedContent')
            && data_get($data, 'model') === 'models/gemini-embedding-2-preview'
            && data_get($data, 'content.parts.0.inline_data.mime_type') === 'image/png';
    });
});

it('returns embeddings from audio, video, and pdf content', function () use ($testAudioBase64, $testVideoBase64, $testPdfBase64): void {
    FixtureResponse::fakeResponseSequence('models/gemini-embedding-2-preview:batchEmbedContents', 'gemini/multimodal-embeddings-batch-media');

    $response = Prism::embeddings()
        ->using(Provider::Gemini, 'gemini-embedding-2-preview')
        ->fromAudio(Audio::fromBase64($testAudioBase64, 'audio/mpeg'))
        ->fromVideo(Video::fromBase64($testVideoBase64, 'video/mp4'))
        ->fromDocument(Document::fromBase64($testPdfBase64, 'application/pdf'))
        ->asEmbeddings();

    expect($response->embeddings)->toHaveCount(3);

    Http::assertSent(function (Request $request): bool {
        $requests = data_get($request->data(), 'requests', []);

        return str_contains($request->url(), 'models/gemini-embedding-2-preview:batchEmbedContents')
            && data_get($requests, '0.content.parts.0.inline_data.mime_type') === 'audio/mpeg'
            && data_get($requests, '1.content.parts.0.inline_data.mime_type') === 'video/mp4'
            && data_get($requests, '2.content.parts.0.inline_data.mime_type') === 'application/pdf';
    });
});

it('returns a single aggregated embedding from grouped multimodal content', function () use ($testImageBase64): void {
    FixtureResponse::fakeResponseSequence('models/gemini-embedding-2-preview:embedContent', 'gemini/multimodal-embeddings-aggregate');

    $response = Prism::embeddings()
        ->using(Provider::Gemini, 'gemini-embedding-2-preview')
        ->fromContent([
            'An image of a dog',
            Image::fromBase64($testImageBase64, 'image/png'),
        ])
        ->asEmbeddings();

    expect($response->embeddings)->toHaveCount(1);

    Http::assertSent(function (Request $request): bool {
        $parts = data_get($request->data(), 'content.parts', []);

        return str_contains($request->url(), 'models/gemini-embedding-2-preview:embedContent')
            && count($parts) === 2
            && data_get($parts, '0.text') === 'An image of a dog'
            && data_get($parts, '1.inline_data.mime_type') === 'image/png';
    });
});

it('returns multiple embeddings from multiple content entries in one request', function () use ($testImageBase64): void {
    FixtureResponse::fakeResponseSequence('models/gemini-embedding-2-preview:batchEmbedContents', 'gemini/multimodal-embeddings-batch');

    $response = Prism::embeddings()
        ->using(Provider::Gemini, 'gemini-embedding-2-preview')
        ->fromInput('The dog is cute')
        ->fromImage(Image::fromBase64($testImageBase64, 'image/png'))
        ->asEmbeddings();

    expect($response->embeddings)->toHaveCount(2);

    Http::assertSent(function (Request $request): bool {
        $requests = data_get($request->data(), 'requests', []);

        return str_contains($request->url(), 'models/gemini-embedding-2-preview:batchEmbedContents')
            && data_get($requests, '0.content.parts.0.text') === 'The dog is cute'
            && data_get($requests, '1.content.parts.0.inline_data.mime_type') === 'image/png';
    });
});

it('forwards gemini embedding provider options for multimodal requests', function () use ($testImageBase64): void {
    FixtureResponse::fakeResponseSequence('models/gemini-embedding-2-preview:batchEmbedContents', 'gemini/multimodal-embeddings-batch');

    Prism::embeddings()
        ->using(Provider::Gemini, 'gemini-embedding-2-preview')
        ->withProviderOptions([
            'title' => 'Dog photo',
            'taskType' => 'SEMANTIC_SIMILARITY',
            'outputDimensionality' => 768,
        ])
        ->fromInput('The dog is cute')
        ->fromImage(Image::fromBase64($testImageBase64, 'image/png'))
        ->asEmbeddings();

    Http::assertSent(function (Request $request): bool {
        $requests = data_get($request->data(), 'requests', []);

        if (count($requests) !== 2) {
            return false;
        }

        foreach ($requests as $embeddingRequest) {
            if (($embeddingRequest['title'] ?? null) !== 'Dog photo') {
                return false;
            }

            if (($embeddingRequest['taskType'] ?? null) !== 'SEMANTIC_SIMILARITY') {
                return false;
            }

            if (($embeddingRequest['outputDimensionality'] ?? null) !== 768) {
                return false;
            }
        }

        return true;
    });
});
