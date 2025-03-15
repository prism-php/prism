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
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY'));
});

it('returns embeddings from input', function (): void {
    FixtureResponse::fakeResponseSequence('v1/embeddings', 'openai/embeddings-input');

    $response = Prism::embeddings()
        ->using(Provider::OpenAI, 'text-embedding-ada-002')
        ->fromInput('The food was delicious and the waiter...')
        ->asEmbeddings();

    $embeddings = json_decode(
        file_get_contents('tests/Fixtures/openai/embeddings-input-1.json'),
        true
    );

    $embeddings = array_map(
        fn (array $item): Embedding => Embedding::fromArray($item['embedding']),
        data_get($embeddings, 'data')
    );

    expect($response->meta->model)->toContain('text-embedding-ada-002');

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embeddings[0]->embedding);
    expect($response->usage->tokens)->toBe(8);
});

it('returns embeddings from file', function (): void {
    FixtureResponse::fakeResponseSequence('v1/embeddings', 'openai/embeddings-file');

    $response = Prism::embeddings()
        ->using(Provider::OpenAI, 'text-embedding-ada-002')
        ->fromFile('tests/Fixtures/test-embedding-file.md')
        ->asEmbeddings();

    $embeddings = json_decode(
        file_get_contents('tests/Fixtures/openai/embeddings-file-1.json'),
        true
    );

    $embeddings = array_map(
        fn (array $item): Embedding => Embedding::fromArray($item['embedding']),
        data_get($embeddings, 'data')
    );

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embeddings[0]->embedding);
    expect($response->usage->tokens)->toBeNumeric();
});

it('works with multiple embeddings', function (): void {
    FixtureResponse::fakeResponseSequence('v1/embeddings', 'openai/embeddings-multiple-inputs');

    $response = Prism::embeddings()
        ->using(Provider::OpenAI, 'text-embedding-ada-002')
        ->fromArray([
            'The food was delicious.',
            'The drinks were not so good',
        ])
        ->asEmbeddings();

    $embeddings = json_decode(
        file_get_contents('tests/Fixtures/openai/embeddings-multiple-inputs-1.json'),
        true
    );

    $embeddings = array_map(
        fn (array $item): Embedding => Embedding::fromArray($item['embedding']),
        data_get($embeddings, 'data')
    );

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embeddings[0]->embedding);
    expect($response->embeddings[1]->embedding)->toBe($embeddings[1]->embedding);
    expect($response->usage->tokens)->toBeNumeric();
});

it('sets the rate limits on the response', function (): void {
    $this->freezeTime(function (Carbon $time): void {
        $time = $time->toImmutable();

        FixtureResponse::fakeResponseSequence('v1/embeddings', 'openai/embeddings-input', [
            'x-ratelimit-limit-requests' => 60,
            'x-ratelimit-limit-tokens' => 150000,
            'x-ratelimit-remaining-requests' => 0,
            'x-ratelimit-remaining-tokens' => 149984,
            'x-ratelimit-reset-requests' => '1s',
            'x-ratelimit-reset-tokens' => '6m30s',
        ]);

        $response = Prism::embeddings()
            ->using(Provider::OpenAI, 'text-embedding-ada-002')
            ->fromInput('The food was delicious and the waiter...')
            ->asEmbeddings();

        expect($response->meta->rateLimits)->toHaveCount(2);
        expect($response->meta->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
        expect($response->meta->rateLimits[0]->name)->toEqual('requests');
        expect($response->meta->rateLimits[0]->limit)->toEqual(60);
        expect($response->meta->rateLimits[0]->remaining)->toEqual(0);
        expect($response->meta->rateLimits[0]->resetsAt->equalTo($time->addSeconds(1)))->toBeTrue();

        expect($response->meta->rateLimits[1]->name)->toEqual('tokens');
        expect($response->meta->rateLimits[1]->limit)->toEqual(150000);
        expect($response->meta->rateLimits[1]->remaining)->toEqual(149984);
        expect($response->meta->rateLimits[1]->resetsAt->equalTo($time->addMinutes(6)->addSeconds(30)))->toBeTrue();
    });
});
