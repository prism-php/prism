<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Prism;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY'));
});

it('can generate an image with dall-e-3', function (): void {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/generated-image.png',
                    'revised_prompt' => 'A cute baby sea otter floating on its back in calm blue water',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 15,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('openai', 'dall-e-3')
        ->prompt('A cute baby sea otter')
        ->generate();

    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->url)->toBe('https://example.com/generated-image.png');
    expect($response->firstImage()->hasUrl())->toBeTrue();
    expect($response->firstImage()->hasB64Json())->toBeFalse();
    expect($response->firstImage()->hasRevisedPrompt())->toBeTrue();
    expect($response->firstImage()->revisedPrompt)->toBe('A cute baby sea otter floating on its back in calm blue water');
    expect($response->usage->promptTokens)->toBe(15);
    expect($response->imageCount())->toBe(1);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/images/generations' &&
               $data['model'] === 'dall-e-3' &&
               $data['prompt'] === 'A cute baby sea otter';
    });
});

it('can generate an image with dall-e-2', function (): void {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/dall-e-2-image.png',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('openai', 'dall-e-2')
        ->prompt('A mountain landscape')
        ->generate();

    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->url)->toBe('https://example.com/dall-e-2-image.png');
    expect($response->firstImage()->revisedPrompt)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'dall-e-2' &&
               $data['prompt'] === 'A mountain landscape';
    });
});

it('can generate an image with gpt-image-1', function (): void {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'b64_json' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 20,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('openai', 'gpt-image-1')
        ->prompt('A futuristic cityscape')
        ->generate();

    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->hasB64Json())->toBeTrue();
    expect($response->firstImage()->hasUrl())->toBeFalse();
    expect($response->firstImage()->b64Json)->toBe('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'gpt-image-1' &&
               $data['prompt'] === 'A futuristic cityscape';
    });
});

it('can generate multiple images with dall-e-2', function (): void {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/image-1.png',
                ],
                [
                    'url' => 'https://example.com/image-2.png',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 12,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('openai', 'dall-e-2')
        ->prompt('Abstract art')
        ->withProviderOptions([
            'n' => 2,
            'size' => '512x512',
        ])
        ->generate();

    expect($response->imageCount())->toBe(2);
    expect($response->images[0]->url)->toBe('https://example.com/image-1.png');
    expect($response->images[1]->url)->toBe('https://example.com/image-2.png');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'dall-e-2' &&
               $data['prompt'] === 'Abstract art' &&
               $data['n'] === 2 &&
               $data['size'] === '512x512';
    });
});

it('can generate an image with all dall-e-3 provider options', function (): void {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/hd-vivid-image.png',
                    'revised_prompt' => 'A highly detailed and vivid sunset over mountain peaks',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 18,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('openai', 'dall-e-3')
        ->prompt('A sunset over mountains')
        ->withProviderOptions([
            'size' => '1792x1024',
            'quality' => 'hd',
            'style' => 'vivid',
            'response_format' => 'url',
        ])
        ->generate();

    expect($response->firstImage()->url)->toBe('https://example.com/hd-vivid-image.png');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'dall-e-3' &&
               $data['prompt'] === 'A sunset over mountains' &&
               $data['size'] === '1792x1024' &&
               $data['quality'] === 'hd' &&
               $data['style'] === 'vivid' &&
               $data['response_format'] === 'url';
    });
});

it('can generate an image with gpt-image-1 provider options', function (): void {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'b64_json' => 'base64ImageData==',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 25,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('openai', 'gpt-image-1')
        ->prompt('A detailed portrait')
        ->withProviderOptions([
            'size' => '1536x1024',
            'quality' => 'high',
            'output_format' => 'webp',
            'output_compression' => 85,
            'background' => 'transparent',
        ])
        ->generate();

    expect($response->firstImage()->b64Json)->toBe('base64ImageData==');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'gpt-image-1' &&
               $data['prompt'] === 'A detailed portrait' &&
               $data['size'] === '1536x1024' &&
               $data['quality'] === 'high' &&
               $data['output_format'] === 'webp' &&
               $data['output_compression'] === 85 &&
               $data['background'] === 'transparent';
    });
});

it('can handle image editing with provider options', function (): void {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'b64_json' => 'editedImageData==',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 30,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('openai', 'gpt-image-1')
        ->prompt('Add a rainbow to the sky')
        ->withProviderOptions([
            'image' => 'base64OriginalImage==',
            'mask' => 'base64MaskImage==',
            'size' => '1024x1024',
        ])
        ->generate();

    expect($response->firstImage()->b64Json)->toBe('editedImageData==');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'gpt-image-1' &&
               $data['prompt'] === 'Add a rainbow to the sky' &&
               $data['image'] === 'base64OriginalImage==' &&
               $data['mask'] === 'base64MaskImage==' &&
               $data['size'] === '1024x1024';
    });
});

it('includes usage information in response', function (): void {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/usage-test.png',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 22,
                'completion_tokens' => 5,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('openai', 'dall-e-3')
        ->prompt('Test usage tracking')
        ->generate();

    expect($response->usage->promptTokens)->toBe(22);
    expect($response->usage->completionTokens)->toBe(5);
});

it('includes meta information in response', function (): void {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'id' => 'img_abc123',
            'model' => 'dall-e-3',
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/meta-test.png',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 15,
                'completion_tokens' => 0,
            ],
        ], 200, [
            'x-ratelimit-limit-requests' => '500',
            'x-ratelimit-remaining-requests' => '499',
        ]),
    ]);

    $response = Prism::image()
        ->using('openai', 'dall-e-3')
        ->prompt('Test meta information')
        ->generate();

    expect($response->meta->id)->toBe('img_abc123');
    expect($response->meta->model)->toBe('dall-e-3');
    expect($response->meta->rateLimits)->not->toBeEmpty();
});
