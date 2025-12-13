<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'fake-key'));
});

it('can moderate text input', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response([
            'id' => 'modr-123',
            'model' => 'omni-moderation-latest',
            'results' => [
                [
                    'flagged' => false,
                    'categories' => [
                        'hate' => false,
                        'harassment' => false,
                        'self-harm' => false,
                    ],
                    'category_scores' => [
                        'hate' => 0.1,
                        'harassment' => 0.05,
                        'self-harm' => 0.01,
                    ],
                ],
            ],
        ], 200),
    ]);

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput('Hello, this is a test message')
        ->asModeration();

    expect($response->isFlagged())->toBeFalse();
    expect($response->results)->toHaveCount(1);
    expect($response->meta->id)->toBe('modr-123');
    expect($response->meta->model)->toBe('omni-moderation-latest');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && $data['input'] === 'Hello, this is a test message'
            && $data['model'] === 'omni-moderation-latest';
    });
});

it('can moderate multiple text inputs', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response([
            'id' => 'modr-123',
            'model' => 'omni-moderation-latest',
            'results' => [
                [
                    'flagged' => false,
                    'categories' => [],
                    'category_scores' => [],
                ],
                [
                    'flagged' => true,
                    'categories' => ['hate' => true],
                    'category_scores' => ['hate' => 0.9],
                ],
            ],
        ], 200),
    ]);

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput('First message', 'Second message')
        ->asModeration();

    expect($response->isFlagged())->toBeTrue();
    expect($response->results)->toHaveCount(2);
    expect($response->flagged())->toHaveCount(1);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && $data['input'] === ['First message', 'Second message'];
    });
});

it('can moderate a single image from URL', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response([
            'id' => 'modr-456',
            'model' => 'omni-moderation-latest',
            'results' => [
                [
                    'flagged' => false,
                    'categories' => [],
                    'category_scores' => [],
                ],
            ],
        ], 200),
    ]);

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput(Image::fromUrl('https://example.com/image.png'))
        ->asModeration();

    expect($response->isFlagged())->toBeFalse();
    expect($response->results)->toHaveCount(1);
    expect($response->meta->model)->toBe('omni-moderation-latest');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && $data['model'] === 'omni-moderation-latest'
            && is_array($data['input'])
            && count($data['input']) === 1
            && $data['input'][0]['type'] === 'image_url'
            && $data['input'][0]['image_url']['url'] === 'https://example.com/image.png';
    });
});

it('can moderate multiple images', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response([
            'id' => 'modr-789',
            'model' => 'omni-moderation-latest',
            'results' => [
                [
                    'flagged' => false,
                    'categories' => [],
                    'category_scores' => [],
                ],
                [
                    'flagged' => true,
                    'categories' => ['sexual' => true],
                    'category_scores' => ['sexual' => 0.85],
                ],
            ],
        ], 200),
    ]);

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput([
            Image::fromUrl('https://example.com/image1.png'),
            Image::fromUrl('https://example.com/image2.png'),
        ])
        ->asModeration();

    expect($response->isFlagged())->toBeTrue();
    expect($response->results)->toHaveCount(2);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && is_array($data['input'])
            && count($data['input']) === 2
            && $data['input'][0]['type'] === 'image_url'
            && $data['input'][1]['type'] === 'image_url';
    });
});

it('can moderate mixed text and image inputs', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response([
            'id' => 'modr-101',
            'model' => 'omni-moderation-latest',
            'results' => [
                [
                    'flagged' => false,
                    'categories' => [],
                    'category_scores' => [],
                ],
                [
                    'flagged' => false,
                    'categories' => [],
                    'category_scores' => [],
                ],
                [
                    'flagged' => true,
                    'categories' => ['hate' => true],
                    'category_scores' => ['hate' => 0.8],
                ],
            ],
        ], 200),
    ]);

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput('This is a text message', Image::fromUrl('https://example.com/image.png'), 'Another text message')
        ->asModeration();

    expect($response->isFlagged())->toBeTrue();
    expect($response->results)->toHaveCount(3);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && is_array($data['input'])
            && count($data['input']) === 3
            && $data['input'][0]['type'] === 'text'
            && $data['input'][0]['text'] === 'This is a text message'
            && $data['input'][1]['type'] === 'image_url'
            && $data['input'][2]['type'] === 'text'
            && $data['input'][2]['text'] === 'Another text message';
    });
});

it('can moderate image from local path', function (): void {
    $imagePath = __DIR__.'/../../Fixtures/sunset.png';

    Http::fake([
        'api.openai.com/v1/moderations' => Http::response([
            'id' => 'modr-202',
            'model' => 'omni-moderation-latest',
            'results' => [
                [
                    'flagged' => false,
                    'categories' => [],
                    'category_scores' => [],
                ],
            ],
        ], 200),
    ]);

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput(Image::fromLocalPath($imagePath))
        ->asModeration();

    expect($response->isFlagged())->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && is_array($data['input'])
            && $data['input'][0]['type'] === 'image_url'
            && str_starts_with((string) $data['input'][0]['image_url']['url'], 'data:');
    });
});

it('can moderate image from base64', function (): void {
    $base64Image = base64_encode(file_get_contents(__DIR__.'/../../Fixtures/sunset.png'));

    Http::fake([
        'api.openai.com/v1/moderations' => Http::response([
            'id' => 'modr-303',
            'model' => 'omni-moderation-latest',
            'results' => [
                [
                    'flagged' => false,
                    'categories' => [],
                    'category_scores' => [],
                ],
            ],
        ], 200),
    ]);

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput(Image::fromBase64($base64Image, 'image/png'))
        ->asModeration();

    expect($response->isFlagged())->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && is_array($data['input'])
            && $data['input'][0]['type'] === 'image_url'
            && str_starts_with((string) $data['input'][0]['image_url']['url'], 'data:image/png;base64,');
    });
});

it('maintains backward compatibility with text-only single input', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response([
            'id' => 'modr-404',
            'model' => 'omni-moderation-latest',
            'results' => [
                [
                    'flagged' => false,
                    'categories' => [],
                    'category_scores' => [],
                ],
            ],
        ], 200),
    ]);

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput('Simple text input')
        ->asModeration();

    expect($response->isFlagged())->toBeFalse();

    // Should send as a string, not an array, for backward compatibility
    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && $data['input'] === 'Simple text input'
            && ! is_array($data['input']);
    });
});

it('sends images as array even when single image', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response([
            'id' => 'modr-505',
            'model' => 'omni-moderation-latest',
            'results' => [
                [
                    'flagged' => false,
                    'categories' => [],
                    'category_scores' => [],
                ],
            ],
        ], 200),
    ]);

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput(Image::fromUrl('https://example.com/single-image.png'))
        ->asModeration();

    expect($response->isFlagged())->toBeFalse();

    // Should send as an array even for single image
    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && is_array($data['input'])
            && count($data['input']) === 1
            && $data['input'][0]['type'] === 'image_url';
    });
});

it('throws exception when withInput receives invalid types', function (): void {
    expect(function (): void {
        Prism::moderation()
            ->using(Provider::OpenAI, 'omni-moderation-latest')
            ->withInput([
                Image::fromUrl('https://example.com/image.png'),
                'not-an-image', // This should be fine - arrays can contain strings and Images
            ]);
    })->not->toThrow(\Prism\Prism\Exceptions\PrismException::class, 'Array items must be strings or Image instances');

    // Test with actually invalid type in array
    expect(function (): void {
        $invalidInput = [new \stdClass]; // Invalid type
        Prism::moderation()
            ->using(Provider::OpenAI, 'omni-moderation-latest')
            ->withInput($invalidInput);
    })->toThrow(\Prism\Prism\Exceptions\PrismException::class, 'Array items must be strings or Image instances');
});

it('can use withInput with mixed types in variadic arguments', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response([
            'id' => 'modr-606',
            'model' => 'omni-moderation-latest',
            'results' => [
                ['flagged' => false, 'categories' => [], 'category_scores' => []],
                ['flagged' => false, 'categories' => [], 'category_scores' => []],
                ['flagged' => false, 'categories' => [], 'category_scores' => []],
            ],
        ], 200),
    ]);

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput('Text 1', Image::fromUrl('https://example.com/image.png'), 'Text 2')
        ->asModeration();

    expect($response->results)->toHaveCount(3);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return is_array($data['input'])
            && count($data['input']) === 3
            && $data['input'][0]['type'] === 'text'
            && $data['input'][1]['type'] === 'image_url'
            && $data['input'][2]['type'] === 'text';
    });
});

it('can use withInput with arrays', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response([
            'id' => 'modr-707',
            'model' => 'omni-moderation-latest',
            'results' => [
                ['flagged' => false, 'categories' => [], 'category_scores' => []],
                ['flagged' => false, 'categories' => [], 'category_scores' => []],
            ],
        ], 200),
    ]);

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput(['Text 1', 'Text 2'])
        ->asModeration();

    expect($response->results)->toHaveCount(2);
});
