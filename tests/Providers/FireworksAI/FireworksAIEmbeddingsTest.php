<?php

declare(strict_types=1);

namespace Tests\Providers\FireworksAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

beforeEach(function (): void {
    config()->set('prism.providers.fireworksai.api_key', env('FIREWORKS_API_KEY', 'fw-1234'));
    config()->set('prism.providers.fireworksai.url', 'https://api.fireworks.ai/inference/v1');
});

describe('Embeddings for FireworksAI', function (): void {
    it('can generate embeddings', function (): void {
        Http::fake([
            'https://api.fireworks.ai/inference/v1/embeddings' => Http::response([
                'object' => 'list',
                'data' => [[
                    'object' => 'embedding',
                    'embedding' => [0.1, 0.2, 0.3, 0.4, 0.5],
                    'index' => 0,
                ]],
                'model' => 'nomic-ai/nomic-embed-text-v1.5',
                'usage' => [
                    'prompt_tokens' => 5,
                    'total_tokens' => 5,
                ],
            ]),
        ]);

        $response = Prism::embeddings()
            ->using(Provider::FireworksAI, 'nomic-ai/nomic-embed-text-v1.5')
            ->fromInput('Hello world')
            ->asEmbeddings();

        expect($response->embeddings)->toHaveCount(1)
            ->and($response->embeddings[0]->embedding)->toBe([0.1, 0.2, 0.3, 0.4, 0.5])
            ->and($response->usage->tokens)->toBe(5)
            ->and($response->meta->model)->toBe('nomic-ai/nomic-embed-text-v1.5');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.fireworks.ai/inference/v1/embeddings'
            && $request['model'] === 'nomic-ai/nomic-embed-text-v1.5'
            && $request['input'] === ['Hello world']
            && $request['encoding_format'] === 'float');
    });

    it('can use dimensions parameter', function (): void {
        Http::fake([
            'https://api.fireworks.ai/inference/v1/embeddings' => Http::response([
                'object' => 'list',
                'data' => [[
                    'object' => 'embedding',
                    'embedding' => array_fill(0, 256, 0.1),
                    'index' => 0,
                ]],
                'model' => 'nomic-ai/nomic-embed-text-v1.5',
                'usage' => [
                    'prompt_tokens' => 5,
                    'total_tokens' => 5,
                ],
            ]),
        ]);

        $response = Prism::embeddings()
            ->using(Provider::FireworksAI, 'nomic-ai/nomic-embed-text-v1.5')
            ->fromInput('Hello world')
            ->withProviderOptions(['dimensions' => 256])
            ->asEmbeddings();

        expect($response->embeddings[0]->embedding)->toHaveCount(256);

        Http::assertSent(fn (Request $request): bool => $request['dimensions'] === 256);
    });
});
