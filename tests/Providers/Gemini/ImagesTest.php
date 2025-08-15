<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

it('can generate an image with gemini models', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => 'Here\'s an image of a soda can in space!',
                            ],
                            [
                                'inlineData' => [
                                    'mimeType' => 'image/png',
                                    'data' => 'giZ2bWl5aW5nIGltYWdlIGRhdGE=',
                                ],
                            ],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                    'index' => 0,
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 20,
                'totalTokenCount' => 30,
                'promptTokensDetails' => [
                    [
                        'modality' => 'TEXT',
                        'tokenCount' => 10,
                    ],
                ],
                'candidatesTokensDetails' => [
                    [
                        'modality' => 'IMAGE',
                        'tokenCount' => 20,
                    ],
                ],
            ],
            'modelVersion' => 'gemini-2.0-flash-preview-image-generation',
            'responseId' => '12345',
        ], 200),
    ]);

    $response = Prism::image()
        ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
        ->withPrompt('A outsized soda can floating in space')
        ->generate();

    expect($response->imageCount())->toBe(1);
    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->base64)->toBe('giZ2bWl5aW5nIGltYWdlIGRhdGE=');
    expect($response->usage->promptTokens)->toBe(10);
    expect($response->usage->completionTokens)->toBe(20);
    expect($response->meta->id)->toBe('12345');
    expect($response->meta->model)->toBe('gemini-2.0-flash-preview-image-generation');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent'
            && data_get($data, 'contents.0.parts.0.text') === 'A outsized soda can floating in space';
    });
});

it('can edit an image with gemini models', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => 'Here\'s a modified image of a soda can in space!',
                            ],
                            [
                                'inlineData' => [
                                    'mimeType' => 'image/png',
                                    'data' => 'yziZ2bWl5aW5nIGltYWdlIGRhdGE=',
                                ],
                            ],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                    'index' => 0,
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 20,
                'totalTokenCount' => 30,
                'promptTokensDetails' => [
                    [
                        'modality' => 'TEXT',
                        'tokenCount' => 10,
                    ],
                ],
                'candidatesTokensDetails' => [
                    [
                        'modality' => 'IMAGE',
                        'tokenCount' => 20,
                    ],
                ],
            ],
            'modelVersion' => 'gemini-2.0-flash-preview-image-generation',
            'responseId' => '9876',
        ], 200),
    ]);

    $response = Prism::image()
        ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
        ->withPrompt('Please modify this image of a soda can in space for testing.')
        ->withProviderOptions([
            'image' => 'yziZ2bWl5aW5nIGltYWdlIGRhdGE=',
            'image_mime_type' => 'image/png',
        ])
        ->generate();

    expect($response->imageCount())->toBe(1);
    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->base64)->toBe('yziZ2bWl5aW5nIGltYWdlIGRhdGE=');
    expect($response->usage->promptTokens)->toBe(10);
    expect($response->usage->completionTokens)->toBe(20);
    expect($response->meta->id)->toBe('9876');
    expect($response->meta->model)->toBe('gemini-2.0-flash-preview-image-generation');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent'
            && data_get($data, 'contents.0.parts.0.text') === 'Please modify this image of a soda can in space for testing.'
            && data_get($data, 'contents.0.parts.1.inline_data.data') === 'yziZ2bWl5aW5nIGltYWdlIGRhdGE='
            && data_get($data, 'contents.0.parts.1.inline_data.mime_type') === 'image/png';
    });
});

it('can generate an image with imagen models', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict' => Http::response([
            'predictions' => [
                [
                    'mimeType' => 'image/png',
                    'data' => 'nyziZ2bWl5aW5nIGltYWdlIGRhdGE=',
                ],
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using(Provider::Gemini, 'imagen-4.0-generate-001')
        ->withPrompt('Make an image of a mouse hugging a giraffe.')
        ->generate();

    expect($response->imageCount())->toBe(1);
    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->base64)->toBe('nyziZ2bWl5aW5nIGltYWdlIGRhdGE=');
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
    Http::fake([
        'generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict' => Http::response([
            'predictions' => [
                [
                    'mimeType' => 'image/png',
                    'data' => 'nyziZ2bWl5aW5nIGltYWdlIGRhdGE=',
                ],
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using(Provider::Gemini, 'imagen-4.0-generate-001')
        ->withPrompt('Make an image of a mouse hugging a giraffe.')
        ->withProviderOptions([
            'n' => 1,
            'size' => '2K',
            'aspect_ratio' => '16:9',
            'person_generation' => 'dont_allow',
        ])
        ->generate();

    expect($response->imageCount())->toBe(1);
    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->base64)->toBe('nyziZ2bWl5aW5nIGltYWdlIGRhdGE=');
    expect($response->usage->promptTokens)->toBe(0);
    expect($response->usage->completionTokens)->toBe(0);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict'
            && data_get($data, 'instances.0.prompt') === 'Make an image of a mouse hugging a giraffe.'
            && data_get($data, 'parameters.numberOfImages') === 1
            && data_get($data, 'parameters.sampleImageSize') === '2K'
            && data_get($data, 'parameters.aspectRatio') === '16:9'
            && data_get($data, 'parameters.personGeneration') === 'dont_allow';
    });
});

it('can generate multiple images with imagen models', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict' => Http::response([
            'predictions' => [
                [
                    'mimeType' => 'image/png',
                    'data' => 'ryziZ2bWl5aW5nIGltYWdlIGRhdGE=',
                ],
                [
                    'mimeType' => 'image/png',
                    'data' => 'hyziZ2bWl5aW5nIGltYWdlIGRhdGE=',
                ],
                [
                    'mimeType' => 'image/png',
                    'data' => 'myziZ2bWl5aW5nIGltYWdlIGRhdGE=',
                ],
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using(Provider::Gemini, 'imagen-4.0-generate-001')
        ->withPrompt('I need an image of a worm in a suit.')
        ->withProviderOptions([
            'n' => 3,
        ])
        ->generate();

    expect($response->imageCount())->toBe(3);
    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->base64)->toBe('ryziZ2bWl5aW5nIGltYWdlIGRhdGE=');
    expect($response->images[1]->base64)->toBe('hyziZ2bWl5aW5nIGltYWdlIGRhdGE=');
    expect($response->images[2]->base64)->toBe('myziZ2bWl5aW5nIGltYWdlIGRhdGE=');
    expect($response->usage->promptTokens)->toBe(0);
    expect($response->usage->completionTokens)->toBe(0);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict'
            && data_get($data, 'instances.0.prompt') === 'I need an image of a worm in a suit.'
            && data_get($data, 'parameters.numberOfImages') === 3;
    });
});
