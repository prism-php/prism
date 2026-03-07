<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Embedding;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', env('GEMINI_API_KEY', 'gk-1234'));
});

it('returns embeddings from input', function (): void {
    FixtureResponse::fakeResponseSequence('models/text-embedding-004:embedContent', 'gemini/embeddings-input');

    $response = Prism::embeddings()
        ->using(Provider::Gemini, 'text-embedding-004')
        ->fromInput('Embed this sentence.')
        ->asEmbeddings();

    $embeddings = json_decode(file_get_contents('tests/Fixtures/gemini/embeddings-input-1.json'), true);
    $embedding = Embedding::fromArray(data_get($embeddings, 'embedding.values'));

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embedding->embedding);
    expect($response->usage->tokens)->toBe(0); // Gemini doesn't provide token usage
});

it('returns embeddings from file', function (): void {
    FixtureResponse::fakeResponseSequence('models/text-embedding-004:embedContent', 'gemini/embeddings-file');

    $response = Prism::embeddings()
        ->using(Provider::Gemini, 'text-embedding-004')
        ->fromFile('tests/Fixtures/test-embedding-file.md')
        ->asEmbeddings();

    $embeddings = json_decode(file_get_contents('tests/Fixtures/gemini/embeddings-file-1.json'), true);
    $embedding = Embedding::fromArray(data_get($embeddings, 'embedding.values'));

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embedding->embedding);
    expect($response->usage->tokens)->toBe(0); // Gemini doesn't provide token usage
});

it('returns batch embeddings from multiple inputs', function (): void {
    FixtureResponse::fakeResponseSequence('models/text-embedding-004:batchEmbedContents', 'gemini/embeddings-batch');

    $response = Prism::embeddings()
        ->using(Provider::Gemini, 'text-embedding-004')
        ->fromInput('First sentence.')
        ->fromInput('Second sentence.')
        ->asEmbeddings();

    $fixture = json_decode(file_get_contents('tests/Fixtures/gemini/embeddings-batch-1.json'), true);

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings)->toHaveCount(2);
    expect($response->embeddings[0]->embedding)->toBe($fixture['embeddings'][0]['values']);
    expect($response->embeddings[1]->embedding)->toBe($fixture['embeddings'][1]['values']);
    expect($response->usage->tokens)->toBe(0);
});

it('returns batch embeddings with provider options', function (): void {
    FixtureResponse::fakeResponseSequence('models/text-embedding-004:batchEmbedContents', 'gemini/embeddings-batch-with-options');

    $response = Prism::embeddings()
        ->using(Provider::Gemini, 'text-embedding-004')
        ->withProviderOptions([
            'title' => 'Test Embedding',
            'taskType' => 'RETRIEVAL_QUERY',
            'outputDimensionality' => 128,
        ])
        ->fromInput('First sentence.')
        ->fromInput('Second sentence.')
        ->asEmbeddings();

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings)->toHaveCount(2);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        if (! isset($data['requests']) || count($data['requests']) !== 2) {
            return false;
        }

        foreach ($data['requests'] as $embeddingRequest) {
            if (($embeddingRequest['title'] ?? null) !== 'Test Embedding') {
                return false;
            }

            if (($embeddingRequest['taskType'] ?? null) !== 'RETRIEVAL_QUERY') {
                return false;
            }

            if (($embeddingRequest['outputDimensionality'] ?? null) !== 128) {
                return false;
            }
        }

        return true;
    });
});

it('returns embeddings with provider meta options', function (): void {
    FixtureResponse::fakeResponseSequence('models/text-embedding-004:embedContent', 'gemini/embeddings-with-meta');

    $response = Prism::embeddings()
        ->using(Provider::Gemini, 'text-embedding-004')
        ->withProviderOptions([
            'title' => 'Test Embedding',
            'taskType' => 'RETRIEVAL_QUERY',
            'outputDimensionality' => 128,
        ])
        ->fromInput('Embed this sentence.')
        ->asEmbeddings();

    $embeddings = json_decode(file_get_contents('tests/Fixtures/gemini/embeddings-with-meta-1.json'), true);
    $embedding = Embedding::fromArray(data_get($embeddings, 'embedding.values'));

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embedding->embedding);
    expect($response->usage->tokens)->toBe(0); // Gemini doesn't provide token usage
});

it('returns embeddings with title specified', function (): void {
    FixtureResponse::fakeResponseSequence('models/text-embedding-004:embedContent', 'gemini/embeddings-with-title');

    $response = Prism::embeddings()
        ->using(Provider::Gemini, 'text-embedding-004')
        ->withProviderOptions(['title' => 'Test Embedding'])
        ->fromInput('Embed this sentence.')
        ->asEmbeddings();

    $embeddings = json_decode(file_get_contents('tests/Fixtures/gemini/embeddings-with-title-1.json'), true);
    $embedding = Embedding::fromArray(data_get($embeddings, 'embedding.values'));

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embedding->embedding);
    expect($response->usage->tokens)->toBe(0); // Gemini doesn't provide token usage
});

it('returns embeddings with task type specified', function (): void {
    FixtureResponse::fakeResponseSequence('models/text-embedding-004:embedContent', 'gemini/embeddings-with-task-type');

    $response = Prism::embeddings()
        ->using(Provider::Gemini, 'text-embedding-004')
        ->withProviderOptions(['taskType' => 'RETRIEVAL_DOCUMENT'])
        ->fromInput('Embed this sentence.')
        ->asEmbeddings();

    $embeddings = json_decode(file_get_contents('tests/Fixtures/gemini/embeddings-with-task-type-1.json'), true);
    $embedding = Embedding::fromArray(data_get($embeddings, 'embedding.values'));

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embedding->embedding);
    expect($response->usage->tokens)->toBe(0); // Gemini doesn't provide token usage
});

it('returns embeddings with output dimensionality specified', function (): void {
    FixtureResponse::fakeResponseSequence('models/text-embedding-004:embedContent', 'gemini/embeddings-with-dimensionality');

    $response = Prism::embeddings()
        ->using(Provider::Gemini, 'text-embedding-004')
        ->withProviderOptions(['outputDimensionality' => 256])
        ->fromInput('Embed this sentence.')
        ->asEmbeddings();

    $embeddings = json_decode(file_get_contents('tests/Fixtures/gemini/embeddings-with-dimensionality-1.json'), true);
    $embedding = Embedding::fromArray(data_get($embeddings, 'embedding.values'));

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embedding->embedding);
    expect($response->usage->tokens)->toBe(0); // Gemini doesn't provide token usage
});
