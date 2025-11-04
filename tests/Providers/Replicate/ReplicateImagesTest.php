<?php

declare(strict_types=1);

namespace Tests\Providers\Replicate;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.replicate.api_key', env('REPLICATE_API_KEY', 'r8_test1234'));
    config()->set('prism.providers.replicate.polling_interval', 10);
    config()->set('prism.providers.replicate.max_wait_time', 10);
});

describe('Image Generation for Replicate', function (): void {
    it('can generate an image with flux-schnell', function (): void {
        $createResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/generate-image-basic-1.json'), true);
        $completedResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/generate-image-basic-2.json'), true);
        $predictionId = $createResponse['id'];
        $imageUrl = $completedResponse['output'][0];

        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response($createResponse, 201),
            "https://api.replicate.com/v1/predictions/{$predictionId}" => Http::response($completedResponse, 200),
            $imageUrl => Http::response('fake-image-content', 200),
        ]);

        $response = Prism::image()
            ->using('replicate', 'black-forest-labs/flux-schnell')
            ->withPrompt('A cute baby sea otter floating on its back in calm blue water')
            ->generate();

        expect($response->firstImage())->not->toBeNull()
            ->and($response->firstImage()->hasUrl())->toBeTrue()
            ->and($response->firstImage()->url)->not->toBeEmpty()
            ->and($response->firstImage()->hasBase64())->toBeTrue()
            ->and($response->firstImage()->base64)->not->toBeEmpty()
            ->and($response->imageCount())->toBe(1);
    });

    it('can generate an image with provider options', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/generate-image-with-options');

        Prism::image()
            ->using('replicate', 'black-forest-labs/flux-schnell')
            ->withPrompt('A mountain landscape at sunset')
            ->withProviderOptions([
                'aspect_ratio' => '16:9',
                'output_format' => 'png',
            ])
            ->generate();

        Http::assertSent(function ($request): bool {
            if (! str_contains((string) $request->url(), 'predictions')) {
                return false;
            }

            $body = json_decode((string) $request->body(), true);

            return isset($body['input']['prompt'])
                && $body['input']['prompt'] === 'A mountain landscape at sunset'
                && isset($body['input']['aspect_ratio'])
                && $body['input']['aspect_ratio'] === '16:9';
        });
    });

    it('includes meta information in response', function (): void {
        $createResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/generate-image-basic-1.json'), true);
        $completedResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/generate-image-basic-2.json'), true);
        $predictionId = $createResponse['id'];
        $imageUrl = $completedResponse['output'][0];

        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response($createResponse, 201),
            "https://api.replicate.com/v1/predictions/{$predictionId}" => Http::response($completedResponse, 200),
            $imageUrl => Http::response('fake-image-content', 200),
        ]);

        $response = Prism::image()
            ->using('replicate', 'black-forest-labs/flux-schnell')
            ->withPrompt('A cute baby sea otter floating on its back in calm blue water')
            ->generate();

        expect($response->meta->id)->not->toBeEmpty()
            ->and($response->meta->model)->toBe('black-forest-labs/flux-schnell');
    });
});
