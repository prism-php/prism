<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Embedding;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openrouter.api_key', env('OPENROUTER_API_KEY'));
});

it('returns embeddings from input', function (): void {
    FixtureResponse::fakeResponseSequence('v1/embeddings', 'openrouter/embeddings-input');

    $response = Prism::embeddings()
        ->using(Provider::OpenRouter, 'openai/text-embedding-ada-002')
        ->fromInput('The food was delicious and the waiter...')
        ->asEmbeddings();

    $embeddings = json_decode(
        file_get_contents('tests/Fixtures/openrouter/embeddings-input-1.json'),
        true
    );

    $embeddings = array_map(
        fn (array $item): Embedding => Embedding::fromArray($item['embedding']),
        data_get($embeddings, 'data')
    );

    expect($response->meta->id)->toBeString();
    expect($response->meta->model)->toContain('text-embedding-ada-002');

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embeddings[0]->embedding);
    expect($response->usage->tokens)->toBe(8);
});

it('returns embeddings from file', function (): void {
    FixtureResponse::fakeResponseSequence('v1/embeddings', 'openrouter/embeddings-file');

    $response = Prism::embeddings()
        ->using(Provider::OpenRouter, 'openai/text-embedding-ada-002')
        ->fromFile('tests/Fixtures/test-embedding-file.md')
        ->asEmbeddings();

    $embeddings = json_decode(
        file_get_contents('tests/Fixtures/openrouter/embeddings-file-1.json'),
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
    FixtureResponse::fakeResponseSequence('v1/embeddings', 'openrouter/embeddings-multiple-inputs');

    $response = Prism::embeddings()
        ->using(Provider::OpenRouter, 'openai/text-embedding-ada-002')
        ->fromArray([
            'The food was delicious.',
            'The drinks were not so good',
        ])
        ->asEmbeddings();

    $embeddings = json_decode(
        file_get_contents('tests/Fixtures/openrouter/embeddings-multiple-inputs-1.json'),
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

it('allows setting provider options like dimensions', function (): void {
    FixtureResponse::fakeResponseSequence('v1/embeddings', 'openrouter/embeddings-with-dimensions');

    $response = Prism::embeddings()
        ->using(Provider::OpenRouter, 'openai/text-embedding-3-small')
        ->withProviderOptions([
            'dimensions' => 256,
        ])
        ->fromInput('The food was delicious and the waiter...')
        ->asEmbeddings();

    $embeddings = json_decode(
        file_get_contents('tests/Fixtures/openrouter/embeddings-with-dimensions-1.json'),
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
