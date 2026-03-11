<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;
use Tests\Fixtures\FixtureResponse;

// Minimal 1x1 red PNG for testing
$testImageBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

it('returns embeddings from an image using the multimodal endpoint', function () use ($testImageBase64): void {
    FixtureResponse::fakeResponseSequence('*', 'voyageai/multimodal-embeddings-image');

    $image = Image::fromBase64($testImageBase64, 'image/png');

    $response = Prism::embeddings()
        ->using(Provider::VoyageAI, 'voyage-multimodal-3')
        ->fromImage($image)
        ->asEmbeddings();

    expect($response->meta->model)->toBe('voyage-multimodal-3');
    expect($response->embeddings)->toBeArray();
    expect($response->embeddings)->toHaveCount(1);
    expect($response->usage->tokens)->toBe(215);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'multimodalembeddings')
        && $request['model'] === 'voyage-multimodal-3'
        && isset($request['inputs'][0]['content'][0])
        && $request['inputs'][0]['content'][0]['type'] === 'image_base64');
});

it('returns embeddings from an image URL using the multimodal endpoint', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'voyageai/multimodal-embeddings-image-url');

    $image = Image::fromUrl('https://example.com/photo.jpg');

    $response = Prism::embeddings()
        ->using(Provider::VoyageAI, 'voyage-multimodal-3')
        ->fromImage($image)
        ->asEmbeddings();

    expect($response->meta->model)->toBe('voyage-multimodal-3');
    expect($response->embeddings)->toBeArray();
    expect($response->embeddings)->toHaveCount(1);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'multimodalembeddings')
        && $request['inputs'][0]['content'][0]['type'] === 'image_url'
        && $request['inputs'][0]['content'][0]['image_url'] === 'https://example.com/photo.jpg');
});

it('returns embeddings from text and image combined', function () use ($testImageBase64): void {
    FixtureResponse::fakeResponseSequence('*', 'voyageai/multimodal-embeddings-text-and-image');

    $image = Image::fromBase64($testImageBase64, 'image/png');

    $response = Prism::embeddings()
        ->using(Provider::VoyageAI, 'voyage-multimodal-3')
        ->fromInput('A photo of a sunset')
        ->fromImage($image)
        ->asEmbeddings();

    expect($response->meta->model)->toBe('voyage-multimodal-3');
    expect($response->embeddings)->toBeArray();
    expect($response->embeddings)->toHaveCount(2);
    expect($response->usage->tokens)->toBe(223);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'multimodalembeddings')
        && $request['inputs'][0]['content'][0]['type'] === 'text'
        && $request['inputs'][1]['content'][0]['type'] === 'image_base64');
});

it('uses the text-only endpoint when no images are provided', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'voyageai/embeddings-from-input');

    $response = Prism::embeddings()
        ->using(Provider::VoyageAI, 'voyage-3-lite')
        ->fromInput('The food was delicious and the waiter...')
        ->asEmbeddings();

    expect($response->meta->model)->toBe('voyage-3-lite');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/embeddings')
        && ! str_contains((string) $request->url(), 'multimodal')
        && isset($request['input']));
});

it('passes inputType provider option to multimodal endpoint', function () use ($testImageBase64): void {
    FixtureResponse::fakeResponseSequence('*', 'voyageai/multimodal-embeddings-image');

    $image = Image::fromBase64($testImageBase64, 'image/png');

    Prism::embeddings()
        ->using(Provider::VoyageAI, 'voyage-multimodal-3')
        ->fromImage($image)
        ->withProviderOptions(['inputType' => 'document'])
        ->asEmbeddings();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'multimodalembeddings')
        && $request['input_type'] === 'document');
});
