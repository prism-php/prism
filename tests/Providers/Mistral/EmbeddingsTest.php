<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Support\Carbon;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.mistral.api_key', env('MISTRAL_API_KEY', 'sk-1234'));
});
it('returns embeddings from input', function (): void {
    FixtureResponse::fakeResponseSequence('v1/embeddings', 'mistral/embeddings-input');

    $response = Prism::embeddings()
        ->using(Provider::Mistral, 'mistral-embed')
        ->fromInput('Embed this sentence.')
        ->asEmbeddings();

    $embeddings = json_decode(file_get_contents('tests/Fixtures/mistral/embeddings-input-1.json'), true);
    $embeddings = array_map(fn (array $item): Embedding => Embedding::fromArray($item['embedding']), data_get($embeddings, 'data'));

    expect($response->meta->id)->toBe('2cb41e23e043468dac0e039f81dd9c23');
    expect($response->meta->model)->toBe('mistral-embed');
    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embeddings[0]->embedding);
    expect($response->usage->tokens)->toBe(7);
});

it('returns embeddings from file', function (): void {
    FixtureResponse::fakeResponseSequence('v1/embeddings', 'mistral/embeddings-file');

    $response = Prism::embeddings()
        ->using(Provider::Mistral, 'mistral-embed')
        ->fromFile('tests/Fixtures/test-embedding-file.md')
        ->asEmbeddings();

    $embeddings = json_decode(file_get_contents('tests/Fixtures/mistral/embeddings-file-1.json'), true);
    $embeddings = array_map(fn (array $item): \Prism\Prism\ValueObjects\Embedding => Embedding::fromArray($item['embedding']), data_get($embeddings, 'data'));

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embeddings[0]->embedding);
    expect($response->usage->tokens)->toBe(1174);
});

it('works with multiple embeddings', function (): void {
    FixtureResponse::fakeResponseSequence('v1/embeddings', 'mistral/embeddings-multiple-inputs');

    $response = Prism::embeddings()
        ->using(Provider::Mistral, 'mistral-embed')
        ->fromArray([
            'The food was delicious.',
            'The drinks were not so good',
        ])
        ->asEmbeddings();

    $embeddings = json_decode(file_get_contents('tests/Fixtures/mistral/embeddings-multiple-inputs-1.json'), true);
    $embeddings = array_map(fn (array $item): \Prism\Prism\ValueObjects\Embedding => Embedding::fromArray($item['embedding']), data_get($embeddings, 'data'));

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embeddings[0]->embedding);
    expect($response->embeddings[1]->embedding)->toBe($embeddings[1]->embedding);
    expect($response->usage->tokens)->toBe(15);
});

it('sets the rate limits on the response', function (): void {
    $this->freezeTime(function (Carbon $time): void {
        $time = $time->toImmutable();

        FixtureResponse::fakeResponseSequence('v1/embeddings', 'mistral/embeddings-input', [
            'ratelimitbysize-limit' => 500000,
            'ratelimitbysize-remaining' => 499900,
            'ratelimitbysize-reset' => 28,
        ]);

        $response = Prism::embeddings()
            ->using(Provider::Mistral, 'mistral-embed')
            ->fromInput('Embed this sentence.')
            ->asEmbeddings();

        expect($response->meta->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
        expect($response->meta->rateLimits[0]->name)->toEqual('tokens');
        expect($response->meta->rateLimits[0]->limit)->toEqual(500000);
        expect($response->meta->rateLimits[0]->remaining)->toEqual(499900);
        expect($response->meta->rateLimits[0]->resetsAt->equalTo($time->addSeconds(28)))->toBeTrue();
    });
});
