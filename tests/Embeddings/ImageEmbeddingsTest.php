<?php

declare(strict_types=1);

namespace Tests\Embeddings;

use Prism\Prism\Embeddings\PendingRequest;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;

it('can add a single image to embedding request', function (): void {
    $image = Image::fromBase64('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $pendingRequest = new PendingRequest;
    $result = $pendingRequest->fromImage($image);

    expect($result)->toBeInstanceOf(PendingRequest::class);
});

it('can add multiple images to embedding request', function (): void {
    $image1 = Image::fromBase64('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $image2 = Image::fromBase64('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $pendingRequest = new PendingRequest;
    $result = $pendingRequest->fromImages([$image1, $image2]);

    expect($result)->toBeInstanceOf(PendingRequest::class);
});

it('request contains images when added', function (): void {
    $image = Image::fromBase64('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $request = new Request(
        model: 'test-model',
        providerKey: 'test-provider',
        inputs: [],
        images: [$image],
        clientOptions: [],
        clientRetry: [],
        providerOptions: [],
    );

    expect($request->hasImages())->toBeTrue();
    expect($request->hasInputs())->toBeFalse();
    expect($request->images())->toHaveCount(1);
    expect($request->images()[0])->toBeInstanceOf(Image::class);
});

it('request can have both text and images', function (): void {
    $image = Image::fromBase64('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $request = new Request(
        model: 'test-model',
        providerKey: 'test-provider',
        inputs: ['Hello world'],
        images: [$image],
        clientOptions: [],
        clientRetry: [],
        providerOptions: [],
    );

    expect($request->hasImages())->toBeTrue();
    expect($request->hasInputs())->toBeTrue();
    expect($request->inputs())->toHaveCount(1);
    expect($request->images())->toHaveCount(1);
});

it('throws exception when no input is provided', function (): void {
    $pendingRequest = new PendingRequest;

    expect(fn () => $pendingRequest->asEmbeddings())
        ->toThrow(PrismException::class, 'Embeddings input is required (text or images)');
});

it('builds request with images-only input', function (): void {
    // Verify a request with only images (no text) is valid
    $image = Image::fromBase64('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $request = new Request(
        model: 'clip-model',
        providerKey: 'custom-provider',
        inputs: [],
        images: [$image],
        clientOptions: [],
        clientRetry: [],
        providerOptions: [],
    );

    expect($request->hasImages())->toBeTrue();
    expect($request->hasInputs())->toBeFalse();
    expect($request->model())->toBe('clip-model');
    expect($request->provider())->toBe('custom-provider');
});
