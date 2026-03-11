<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;
use Tests\Fixtures\FixtureResponse;

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
        ->withPrompt('A cute baby sea otter')
        ->generate();

    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->url)->toBe('https://example.com/generated-image.png');
    expect($response->firstImage()->hasUrl())->toBeTrue();
    expect($response->firstImage()->hasRevisedPrompt())->toBeTrue();
    expect($response->firstImage()->revisedPrompt)->toBe('A cute baby sea otter floating on its back in calm blue water');
    expect($response->usage->promptTokens)->toBe(15);
    expect($response->imageCount())->toBe(1);
    expect($response->additionalContent)->toBe([]);

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
        ->withPrompt('A mountain landscape')
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
        ->withPrompt('Abstract art')
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
        ->withPrompt('A sunset over mountains')
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
        ->withPrompt('Test usage tracking')
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
        ->withPrompt('Test meta information')
        ->generate();

    expect($response->meta->id)->toBe('img_abc123');
    expect($response->meta->model)->toBe('dall-e-3');
});

it('can generate an image with gpt-image-1 returning base64', function (): void {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'background' => 'opaque',
            'quality' => 'high',
            'size' => '1024x1024',
            'output_format' => 'png',
            'data' => [
                [
                    'b64_json' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
                ],
            ],
            'usage' => [
                'total_tokens' => 100,
                'input_tokens' => 50,
                'output_tokens' => 50,
                'input_tokens_details' => [
                    'text_tokens' => 10,
                    'image_tokens' => 40,
                ],
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('openai', 'gpt-image-1')
        ->withPrompt('A cute baby sea otter')
        ->generate();

    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->base64)->toBe('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    expect($response->firstImage()->hasBase64())->toBeTrue();
    expect($response->firstImage()->hasUrl())->toBeFalse();
    expect($response->firstImage()->url)->toBeNull();
    expect($response->usage->promptTokens)->toBe(50);
    expect($response->imageCount())->toBe(1);
    expect($response->additionalContent)->toBe([
        'input_tokens_details' => [
            'text_tokens' => 10,
            'image_tokens' => 40,
        ],
        'quality' => 'high',
        'size' => '1024x1024',
        'output_format' => 'png',
        'background' => 'opaque',
    ]);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/images/generations' &&
               $data['model'] === 'gpt-image-1' &&
               $data['prompt'] === 'A cute baby sea otter';
    });
});

it('can generate an image with dall-e-3 requesting base64 format', function (): void {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'b64_json' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
                    'revised_prompt' => 'A highly detailed mountain sunset scene',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 20,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('openai', 'dall-e-3')
        ->withPrompt('A mountain sunset')
        ->withProviderOptions([
            'response_format' => 'b64_json',
        ])
        ->generate();

    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->base64)->toBe('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    expect($response->firstImage()->hasBase64())->toBeTrue();
    expect($response->firstImage()->hasUrl())->toBeFalse();
    expect($response->firstImage()->hasRevisedPrompt())->toBeTrue();
    expect($response->firstImage()->revisedPrompt)->toBe('A highly detailed mountain sunset scene');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'dall-e-3' &&
               $data['prompt'] === 'A mountain sunset' &&
               $data['response_format'] === 'b64_json';
    });
});

it('can generate an image with dall-e-2 requesting base64 format', function (): void {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'b64_json' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
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
        ->withPrompt('Abstract geometric patterns')
        ->withProviderOptions([
            'response_format' => 'b64_json',
            'size' => '256x256',
        ])
        ->generate();

    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->base64)->toBe('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    expect($response->firstImage()->hasBase64())->toBeTrue();
    expect($response->firstImage()->hasUrl())->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'dall-e-2' &&
               $data['prompt'] === 'Abstract geometric patterns' &&
               $data['response_format'] === 'b64_json' &&
               $data['size'] === '256x256';
    });
});

it('can edit images', function (): void {
    FixtureResponse::fakeResponseSequence(
        'api.openai.com/v1/images/edits',
        'openai/image-edit'
    );

    $response = Prism::image()
        ->using('openai', 'gpt-image-1')
        ->withPrompt('Add a vaporwave sunset to the background', [
            Image::fromLocalPath('tests/Fixtures/diamond.png'),
        ])
        ->withProviderOptions([
            'size' => '1024x1024',
            'output_format' => 'png',
            'quality' => 'high',
        ])
        ->withClientOptions(['timeout' => 9999])
        ->generate();

    expect($response->firstImage()->base64)->not->toBeEmpty();
    expect($response->additionalContent)->toHaveKeys([
        'input_tokens_details',
        'quality',
        'size',
        'output_format',
        'background',
    ]);
    expect($response->additionalContent['input_tokens_details'])->toHaveKeys(['text_tokens', 'image_tokens']);
});

it('can edit with multiple images', function (): void {
    FixtureResponse::fakeResponseSequence(
        'api.openai.com/v1/images/edits',
        'openai/image-edit-multiple'
    );

    $response = Prism::image()
        ->using('openai', 'gpt-image-1')
        ->withPrompt('Add a vaporwave sunset to the background', [
            Image::fromLocalPath('tests/Fixtures/diamond.png'),
            Image::fromLocalPath('tests/Fixtures/sunset.png')
                ->as('sunset.png'),
        ])
        ->withProviderOptions([
            'size' => '1024x1024',
            'output_format' => 'png',
            'quality' => 'high',
        ])
        ->withClientOptions(['timeout' => 9999])
        ->generate();

    expect($response->firstImage()->base64)->not->toBeEmpty();

    Http::assertSent(function (Request $request): true {
        $images = collect($request->data())
            ->where(fn ($data): bool => $data['name'] === 'image[]')
            ->pluck('filename')
            ->toArray();

        expect($images)->toBe([
            'image-0',
            'sunset.png',
        ]);

        return true;
    });
});

it('can edit images with mask using Image value object', function (): void {
    FixtureResponse::fakeResponseSequence(
        'api.openai.com/v1/images/edits',
        'openai/image-edit-with-mask'
    );

    $response = Prism::image()
        ->using('openai', 'gpt-image-1')
        ->withPrompt('Add a vaporwave sunset to the background', [
            Image::fromLocalPath('tests/Fixtures/diamond.png'),
        ])
        ->withProviderOptions([
            'mask' => Image::fromLocalPath('tests/Fixtures/diamond_background_removed.png'),
            'size' => '1024x1024',
            'output_format' => 'png',
            'quality' => 'high',
        ])
        ->withClientOptions(['timeout' => 9999])
        ->generate();

    expect($response->firstImage()->base64)->not->toBeEmpty();
});

it('throws exception when mask is not Image value object', function (): void {
    $mask = fopen('tests/Fixtures/diamond.png', 'r');

    Prism::image()
        ->using('openai', 'gpt-image-1')
        ->withPrompt('Add a vaporwave sunset to the background', [
            Image::fromLocalPath('tests/Fixtures/diamond.png'),
        ])
        ->withProviderOptions([
            'mask' => $mask,
            'size' => '1024x1024',
        ])
        ->generate();
})->throws(\InvalidArgumentException::class, 'Mask must be an instance of Image value object');
