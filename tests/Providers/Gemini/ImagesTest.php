<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', env('GEMINI_API_KEY', ''));
});

it('can generate an image with gemini models', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent',
        'gemini/generate-image-with-a-prompt'
    );

    $response = Prism::image()
        ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
        ->withPrompt('A outsized soda can floating in space')
        ->generate();

    expect($response->imageCount())->toBe(1);
    expect($response->firstImage())->not->toBeNull();
    expect($response->usage->promptTokens)->toBe(8);
    expect($response->usage->completionTokens)->toBe(1360);
    expect($response->meta->id)->toBe('-ySmaKa-HJfSjMcP8qrtsQw');
    expect($response->meta->model)->toBe('gemini-2.0-flash-preview-image-generation');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent'
            && data_get($data, 'contents.0.parts.0.text') === 'A outsized soda can floating in space';
    });
});

it('can edit an image with gemini models', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent',
        'gemini/generate-image-with-image-edit'
    );

    $originalImage = fopen('tests/Fixtures/diamond.png', 'r');

    $response = Prism::image()
        ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
        ->withPrompt('Add a vaporwave sunset to the background')
        ->withProviderOptions([
            'image' => $originalImage,
            'image_mime_type' => 'image/png',
        ])
        ->generate();

    expect($response->imageCount())->toBe(1);
    expect($response->firstImage())->not->toBeNull();
    expect($response->usage->promptTokens)->toBe(266);
    expect($response->usage->completionTokens)->toBe(1355);
    expect($response->meta->id)->toBe('vi6maLTBCKbP_uMPm5TcqQI');
    expect($response->meta->model)->toBe('gemini-2.0-flash-preview-image-generation');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent'
            && data_get($data, 'contents.0.parts.0.text') === 'Add a vaporwave sunset to the background'
            && data_get($data, 'contents.0.parts.1.inline_data.mime_type') === 'image/png';
    });
});

it('can generate an image with imagen models', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/imagen-4.0-generate-001:predict',
        'gemini/generate-image-with-a-prompt-imagen'
    );

    $response = Prism::image()
        ->using(Provider::Gemini, 'imagen-4.0-generate-001')
        ->withPrompt('Make an image of a mouse hugging a giraffe.')
        ->generate();

    expect($response->imageCount())->toBe(4);
    expect($response->firstImage())->not->toBeNull();
    expect($response->usage->promptTokens)->toBe(0);
    expect($response->usage->completionTokens)->toBe(0);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict'
            && data_get($data, 'instances.0.prompt') === 'Make an image of a mouse hugging a giraffe.';
    });
});

it('can generate an image with imagen models and all options', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/imagen-4.0-generate-001:predict',
        'gemini/generate-image-with-a-prompt-imagen-options'
    );

    $response = Prism::image()
        ->using(Provider::Gemini, 'imagen-4.0-generate-001')
        ->withPrompt('Make an image of an elephant hugging a giraffe.')
        ->withProviderOptions([
            'n' => 1,
            'size' => '2K',
            'aspect_ratio' => '16:9',
            'person_generation' => 'dont_allow',
        ])
        ->generate();

    expect($response->imageCount())->toBe(1);
    expect($response->firstImage())->not->toBeNull();
    expect($response->usage->promptTokens)->toBe(0);
    expect($response->usage->completionTokens)->toBe(0);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict'
            && data_get($data, 'instances.0.prompt') === 'Make an image of an elephant hugging a giraffe.'
            && data_get($data, 'parameters.sampleCount') === 1
            && data_get($data, 'parameters.sampleImageSize') === '2K'
            && data_get($data, 'parameters.aspectRatio') === '16:9'
            && data_get($data, 'parameters.personGeneration') === 'dont_allow';
    });
});

it('can generate an image with image flash models and all options', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent',
        'gemini/generate-image-with-a-prompt'
    );

    $response = Prism::image()
        ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
        ->withPrompt('Make an image of an elephant hugging a giraffe.')
        ->withProviderOptions([
            'aspect_ratio' => '16:9',
        ])
        ->generate();

    expect($response->imageCount())->toBe(1);
    expect($response->firstImage())->not->toBeNull();
    expect($response->usage->promptTokens)->toBe(8);
    expect($response->usage->completionTokens)->toBe(1360);
    expect($response->meta->id)->toBe('-ySmaKa-HJfSjMcP8qrtsQw');
    expect($response->meta->model)->toBe('gemini-2.0-flash-preview-image-generation');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent'
            && data_get($data, 'generationConfig.imageConfig.aspectRatio') === '16:9';
    });
});

it('can generate multiple images with imagen models', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/imagen-4.0-generate-001:predict',
        'gemini/generate-image-with-a-prompt-imagen-multiple'
    );

    $response = Prism::image()
        ->using(Provider::Gemini, 'imagen-4.0-generate-001')
        ->withPrompt('I need an image of a worm in a suit.')
        ->withProviderOptions([
            'n' => 3,
        ])
        ->generate();

    expect($response->imageCount())->toBe(3);
    expect($response->firstImage())->not->toBeNull();
    expect($response->usage->promptTokens)->toBe(0);
    expect($response->usage->completionTokens)->toBe(0);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict'
            && data_get($data, 'instances.0.prompt') === 'I need an image of a worm in a suit.'
            && data_get($data, 'parameters.sampleCount') === 3;
    });
});
