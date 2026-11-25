<?php

declare(strict_types=1);

namespace Tests\Providers\ModelsLab;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;

beforeEach(function (): void {
    config()->set('prism.providers.modelslab.api_key', 'test-api-key');
});

it('can generate an image with text2img', function (): void {
    Http::fake([
        'modelslab.com/api/v6/images/text2img' => Http::response([
            'status' => 'success',
            'id' => 12345,
            'output' => ['https://example.com/generated-image.png'],
            'meta' => [
                'prompt' => 'A cute baby sea otter',
                'seed' => 123456,
            ],
            'generationTime' => 2.5,
        ], 200),
    ]);

    $response = Prism::image()
        ->using(Provider::ModelsLab, 'flux')
        ->withPrompt('A cute baby sea otter')
        ->generate();

    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->url)->toBe('https://example.com/generated-image.png');
    expect($response->firstImage()->hasUrl())->toBeTrue();
    expect($response->imageCount())->toBe(1);
    expect($response->meta->id)->toBe('12345');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://modelslab.com/api/v6/images/text2img' &&
               $data['prompt'] === 'A cute baby sea otter' &&
               $data['key'] === 'test-api-key';
    });
});

it('can generate an image with provider options', function (): void {
    Http::fake([
        'modelslab.com/api/v6/images/text2img' => Http::response([
            'status' => 'success',
            'id' => 12346,
            'output' => ['https://example.com/hd-image.png'],
        ], 200),
    ]);

    $response = Prism::image()
        ->using(Provider::ModelsLab, 'flux')
        ->withPrompt('A sunset over mountains')
        ->withProviderOptions([
            'width' => 1024,
            'height' => 1024,
            'samples' => 1,
            'negative_prompt' => 'blurry, low quality',
        ])
        ->generate();

    expect($response->firstImage()->url)->toBe('https://example.com/hd-image.png');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['width'] === 1024 &&
               $data['height'] === 1024 &&
               $data['negative_prompt'] === 'blurry, low quality';
    });
});

it('can generate multiple images', function (): void {
    Http::fake([
        'modelslab.com/api/v6/images/text2img' => Http::response([
            'status' => 'success',
            'id' => 12347,
            'output' => [
                'https://example.com/image-1.png',
                'https://example.com/image-2.png',
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using(Provider::ModelsLab, 'flux')
        ->withPrompt('Abstract art')
        ->withProviderOptions([
            'samples' => 2,
        ])
        ->generate();

    expect($response->imageCount())->toBe(2);
    expect($response->images[0]->url)->toBe('https://example.com/image-1.png');
    expect($response->images[1]->url)->toBe('https://example.com/image-2.png');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['samples'] === 2;
    });
});

it('can edit an image with img2img', function (): void {
    Http::fake([
        'modelslab.com/api/v6/images/img2img' => Http::response([
            'status' => 'success',
            'id' => 12348,
            'output' => ['https://example.com/edited-image.png'],
        ], 200),
    ]);

    $originalImage = Image::fromLocalPath('tests/Fixtures/diamond.png');

    $response = Prism::image()
        ->using(Provider::ModelsLab, 'flux')
        ->withPrompt('Add a vaporwave sunset to the background', [$originalImage])
        ->generate();

    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->url)->toBe('https://example.com/edited-image.png');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://modelslab.com/api/v6/images/img2img' &&
               $data['prompt'] === 'Add a vaporwave sunset to the background' &&
               isset($data['init_image']);
    });
});

it('can edit an image with img2img using URL', function (): void {
    Http::fake([
        'modelslab.com/api/v6/images/img2img' => Http::response([
            'status' => 'success',
            'id' => 12349,
            'output' => ['https://example.com/edited-image-from-url.png'],
        ], 200),
    ]);

    $originalImage = Image::fromUrl('https://example.com/source-image.png');

    $response = Prism::image()
        ->using(Provider::ModelsLab, 'flux')
        ->withPrompt('Transform this image', [$originalImage])
        ->generate();

    expect($response->firstImage()->url)->toBe('https://example.com/edited-image-from-url.png');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['init_image'] === 'https://example.com/source-image.png';
    });
});

it('handles async processing status', function (): void {
    Http::fake([
        'modelslab.com/api/v6/images/text2img' => Http::response([
            'status' => 'processing',
            'id' => 12350,
            'fetch_result' => 'https://modelslab.com/api/v6/images/fetch/12350',
        ], 200),
        'modelslab.com/api/v6/images/fetch/12350' => Http::response([
            'status' => 'success',
            'id' => 12350,
            'output' => ['https://example.com/async-image.png'],
        ], 200),
    ]);

    $response = Prism::image()
        ->using(Provider::ModelsLab, 'flux')
        ->withPrompt('Complex image generation')
        ->generate();

    expect($response->firstImage()->url)->toBe('https://example.com/async-image.png');
});

it('includes additional content in response', function (): void {
    Http::fake([
        'modelslab.com/api/v6/images/text2img' => Http::response([
            'status' => 'success',
            'id' => 12351,
            'output' => ['https://example.com/image.png'],
            'generationTime' => 3.5,
            'meta' => [
                'seed' => 987654,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using(Provider::ModelsLab, 'flux')
        ->withPrompt('Test image')
        ->generate();

    expect($response->additionalContent['generation_time'])->toBe(3.5);
    expect($response->additionalContent['seed'])->toBe(987654);
});

it('passes model_id in provider options', function (): void {
    Http::fake([
        'modelslab.com/api/v6/images/text2img' => Http::response([
            'status' => 'success',
            'id' => 12352,
            'output' => ['https://example.com/sdxl-image.png'],
        ], 200),
    ]);

    $response = Prism::image()
        ->using(Provider::ModelsLab, 'flux')
        ->withPrompt('A beautiful landscape')
        ->withProviderOptions([
            'model_id' => 'sdxl',
        ])
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model_id'] === 'sdxl';
    });
});
