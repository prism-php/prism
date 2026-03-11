<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'fake-key'));
});

it('can moderate text input', function (): void {
    FixtureResponse::fakeResponseSequence(
        'api.openai.com/v1/moderations',
        'openai/moderation-text-input'
    );

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput('Hello, this is a test message')
        ->asModeration();

    expect($response->isFlagged())->toBeFalse();
    expect($response->results)->toHaveCount(1);
    expect($response->meta->id)->toBe('modr-4377');
    expect($response->meta->model)->toBe('omni-moderation-latest');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && $data['input'] === 'Hello, this is a test message'
            && $data['model'] === 'omni-moderation-latest';
    });
});

it('can moderate multiple text inputs', function (): void {
    FixtureResponse::fakeResponseSequence(
        'api.openai.com/v1/moderations',
        'openai/moderation-multiple-text-inputs'
    );

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput('First message', 'Second message')
        ->asModeration();

    expect($response->isFlagged())->toBeFalse();
    expect($response->results)->toHaveCount(2);
    expect($response->meta->id)->toBe('modr-6876');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && $data['input'] === ['First message', 'Second message'];
    });
});

it('can moderate a single image from URL', function (): void {
    FixtureResponse::fakeResponseSequence(
        'api.openai.com/v1/moderations',
        'openai/moderation-single-image'
    );

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput(Image::fromUrl('https://prismphp.com/storage/diamond.png'))
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
            && $data['input'][0]['image_url']['url'] === 'https://prismphp.com/storage/diamond.png';
    });
});

it('can moderate mixed text and image inputs', function (): void {
    FixtureResponse::fakeResponseSequence(
        'api.openai.com/v1/moderations',
        'openai/moderation-mixed-inputs'
    );

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput('This is a text message', Image::fromUrl('https://prismphp.com/storage/diamond.png'))
        ->asModeration();

    expect($response->isFlagged())->toBeFalse();
    expect($response->results)->toHaveCount(1);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && is_array($data['input'])
            && count($data['input']) === 2
            && $data['input'][0]['type'] === 'text'
            && $data['input'][0]['text'] === 'This is a text message'
            && $data['input'][1]['type'] === 'image_url';
    });
});

it('can moderate image from local path', function (): void {
    $imagePath = __DIR__.'/../../Fixtures/sunset.png';

    FixtureResponse::fakeResponseSequence(
        'api.openai.com/v1/moderations',
        'openai/moderation-image-local-path'
    );

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput(Image::fromLocalPath($imagePath))
        ->asModeration();

    expect($response->isFlagged())->toBeFalse();
    expect($response->meta->id)->toBe('modr-5156');

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

    FixtureResponse::fakeResponseSequence(
        'api.openai.com/v1/moderations',
        'openai/moderation-image-base64'
    );

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput(Image::fromBase64($base64Image, 'image/png'))
        ->asModeration();

    expect($response->isFlagged())->toBeFalse();
    expect($response->meta->id)->toBe('modr-5369');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && is_array($data['input'])
            && $data['input'][0]['type'] === 'image_url'
            && str_starts_with((string) $data['input'][0]['image_url']['url'], 'data:image/png;base64,');
    });
});

it('maintains backward compatibility with text-only single input', function (): void {
    FixtureResponse::fakeResponseSequence(
        'api.openai.com/v1/moderations',
        'openai/moderation-backward-compat'
    );

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput('Simple text input')
        ->asModeration();

    expect($response->isFlagged())->toBeFalse();
    expect($response->meta->id)->toBe('modr-6311');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && $data['input'] === 'Simple text input'
            && ! is_array($data['input']);
    });
});

it('sends images as array even when single image', function (): void {
    FixtureResponse::fakeResponseSequence(
        'api.openai.com/v1/moderations',
        'openai/moderation-single-image-array'
    );

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput(Image::fromUrl('https://prismphp.com/storage/diamond.png'))
        ->asModeration();

    expect($response->isFlagged())->toBeFalse();

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
                Image::fromUrl('https://prismphp.com/storage/diamond.png'),
                'valid-string-input',
            ]);
    })->not->toThrow(PrismException::class, 'Array items must be strings or Image instances');

    expect(function (): void {
        $invalidInput = [new \stdClass];
        Prism::moderation()
            ->using(Provider::OpenAI, 'omni-moderation-latest')
            ->withInput($invalidInput);
    })->toThrow(PrismException::class, 'Array items must be strings or Image instances');
});

it('can use withInput with mixed types in variadic arguments', function (): void {
    FixtureResponse::fakeResponseSequence(
        'api.openai.com/v1/moderations',
        'openai/moderation-mixed-variadic'
    );

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput('Text 1', Image::fromUrl('https://prismphp.com/storage/diamond.png'))
        ->asModeration();

    expect($response->results)->toHaveCount(1);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return is_array($data['input'])
            && count($data['input']) === 2
            && $data['input'][0]['type'] === 'text'
            && $data['input'][1]['type'] === 'image_url';
    });
});

it('can use withInput with arrays', function (): void {
    FixtureResponse::fakeResponseSequence(
        'api.openai.com/v1/moderations',
        'openai/moderation-input-arrays'
    );

    $response = Prism::moderation()
        ->using(Provider::OpenAI, 'omni-moderation-latest')
        ->withInput(['Text 1', 'Text 2'])
        ->asModeration();

    expect($response->results)->toHaveCount(2);
    expect($response->meta->id)->toBe('modr-6378');
});
