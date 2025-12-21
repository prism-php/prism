<?php

declare(strict_types=1);

namespace Tests\Embeddings;

use Prism\Prism\Embeddings\PendingRequest;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Media\Image;

// Minimal 1x1 red PNG for testing
$testImageBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

it('adds a single image to embedding request', function () use ($testImageBase64): void {
    $image = Image::fromBase64($testImageBase64);

    $pendingRequest = new PendingRequest;
    $result = $pendingRequest->fromImage($image);

    expect($result)->toBeInstanceOf(PendingRequest::class);
});

it('adds multiple images to embedding request', function () use ($testImageBase64): void {
    $image1 = Image::fromBase64($testImageBase64);
    $image2 = Image::fromBase64($testImageBase64);

    $pendingRequest = new PendingRequest;
    $result = $pendingRequest->fromImages([$image1, $image2]);

    expect($result)->toBeInstanceOf(PendingRequest::class);
});

it('returns images from request', function () use ($testImageBase64): void {
    $image = Image::fromBase64($testImageBase64);

    $request = new Request(
        model: 'test-model',
        providerKey: 'test-provider',
        inputs: [],
        images: [$image],
        clientOptions: [],
        clientRetry: [],
        providerOptions: [],
    );

    expect($request->images())->toHaveCount(1);
    expect($request->images()[0])->toBeInstanceOf(Image::class);
});

it('returns true for hasImages when images are present', function () use ($testImageBase64): void {
    $image = Image::fromBase64($testImageBase64);

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
});

it('returns true for hasInputs when text inputs are present', function (): void {
    $request = new Request(
        model: 'test-model',
        providerKey: 'test-provider',
        inputs: ['Hello world'],
        images: [],
        clientOptions: [],
        clientRetry: [],
        providerOptions: [],
    );

    expect($request->hasInputs())->toBeTrue();
    expect($request->hasImages())->toBeFalse();
});

it('supports both text and images in the same request', function () use ($testImageBase64): void {
    $image = Image::fromBase64($testImageBase64);

    $request = new Request(
        model: 'multimodal-model',
        providerKey: 'test-provider',
        inputs: ['Describe this image'],
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

it('throws exception when no text or images are provided', function (): void {
    $pendingRequest = new PendingRequest;

    expect(fn (): Response => $pendingRequest->asEmbeddings())
        ->toThrow(PrismException::class, 'Embeddings input is required (text or images)');
});

it('returns model and provider from request', function () use ($testImageBase64): void {
    $image = Image::fromBase64($testImageBase64);

    $request = new Request(
        model: 'bge-visualized',
        providerKey: 'local-bge-vl',
        inputs: [],
        images: [$image],
        clientOptions: [],
        clientRetry: [],
        providerOptions: [],
    );

    expect($request->model())->toBe('bge-visualized');
    expect($request->provider())->toBe('local-bge-vl');
});

it('chains fromImage and fromInput for multimodal requests', function () use ($testImageBase64): void {
    $image = Image::fromBase64($testImageBase64);

    $pendingRequest = new PendingRequest;
    $result = $pendingRequest
        ->fromImage($image)
        ->fromInput('Find similar products but in red');

    expect($result)->toBeInstanceOf(PendingRequest::class);
});

it('chains fromInput and fromImage in either order', function () use ($testImageBase64): void {
    $image = Image::fromBase64($testImageBase64);

    $pendingRequest = new PendingRequest;
    $result = $pendingRequest
        ->fromInput('Describe this product')
        ->fromImage($image);

    expect($result)->toBeInstanceOf(PendingRequest::class);
});
