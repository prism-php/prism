<?php

declare(strict_types=1);

namespace Tests\Providers\VertexAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Embedding;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.vertexai.project_id', 'test-project');
    config()->set('prism.providers.vertexai.location', 'us-central1');
    config()->set('prism.providers.vertexai.api_key', 'test-key-1234');
});

it('returns embeddings from input', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/embeddings-input');

    $response = Prism::embeddings()
        ->using(Provider::VertexAI, 'text-embedding-005')
        ->fromInput('Embed this sentence.')
        ->asEmbeddings();

    $fixture = json_decode(file_get_contents('tests/Fixtures/vertexai/embeddings-input-1.json'), true);
    $embedding = Embedding::fromArray(data_get($fixture, 'predictions.0.embeddings.values'));

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embedding->embedding);
    expect($response->usage->tokens)->toBe(5);
});

it('sends embeddings requests to the correct Vertex AI URL with predict endpoint', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/embeddings-input');

    Prism::embeddings()
        ->using(Provider::VertexAI, 'text-embedding-005')
        ->fromInput('Embed this sentence.')
        ->asEmbeddings();

    Http::assertSent(function (Request $request): bool {
        $url = $request->url();

        expect($url)->toContain('aiplatform.googleapis.com')
            ->and($url)->toContain('projects/test-project')
            ->and($url)->toContain('locations/us-central1')
            ->and($url)->toContain('publishers/google/models')
            ->and($url)->toContain('text-embedding-005:predict');

        return true;
    });
});

it('does not include model in the request body', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/embeddings-input');

    Prism::embeddings()
        ->using(Provider::VertexAI, 'text-embedding-005')
        ->fromInput('Embed this sentence.')
        ->asEmbeddings();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        expect($data)->not->toHaveKey('model');
        expect($data)->toHaveKey('instances');
        expect($data['instances'][0])->toHaveKey('content');
        expect($data['instances'][0]['content'])->toBe('Embed this sentence.');

        return true;
    });
});

it('passes provider options as instance and parameters', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/embeddings-with-options');

    Prism::embeddings()
        ->using(Provider::VertexAI, 'text-embedding-005')
        ->withProviderOptions([
            'taskType' => 'RETRIEVAL_DOCUMENT',
            'title' => 'Test Document',
            'outputDimensionality' => 256,
        ])
        ->fromInput('Embed this sentence.')
        ->asEmbeddings();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        expect($data['instances'][0])->toHaveKey('task_type');
        expect($data['instances'][0]['task_type'])->toBe('RETRIEVAL_DOCUMENT');
        expect($data['instances'][0])->toHaveKey('title');
        expect($data['instances'][0]['title'])->toBe('Test Document');
        expect($data['parameters'])->toHaveKey('outputDimensionality');
        expect($data['parameters']['outputDimensionality'])->toBe(256);

        return true;
    });
});
