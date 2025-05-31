<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\VoyageAI\ValueObjects\Rerank;
use Prism\Prism\Providers\VoyageAI\VoyageAI;
use Prism\Prism\Rerank\Request as RerankRequest;
use Tests\Fixtures\FixtureResponse;

it('returns reranks from documents', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'voyageai/rerank-with-documents');

    $response = VoyageAI::reranks(model: 'rerank-2-lite')
        ->withQuery('Sample query')
        ->withDocuments(['Sample document 1', 'Sample document 2'])
        ->withProviderOptions(['return_documents' => true])
        ->asRerank();

    $reranks = json_decode(file_get_contents('tests/Fixtures/voyageai/rerank-with-documents-1.json'), true);

    $request = new RerankRequest('', '', [], [], [], []);
    $reranks = array_map(fn (array $item): Rerank => Rerank::fromArray($item, $request), data_get($reranks, 'data'));

    expect($response->reranks)->toBeArray();
    expect($response->reranks[0]->index)->toEqual($reranks[0]->index);
    expect($response->reranks[0]->score)->toEqual($reranks[0]->score);
    expect($response->reranks[0]->document)->toEqual($reranks[0]->document);
    expect($response->reranks[1]->index)->toEqual($reranks[1]->index);
    expect($response->reranks[1]->score)->toEqual($reranks[1]->score);
    expect($response->reranks[1]->document)->toEqual($reranks[1]->document);
    expect($response->usage->tokens)->toBe(26);
});

it('throws a PrismRateLimitedException for a 429 response code', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    VoyageAI::reranks(model: 'rerank-2-lite')
        ->withQuery('query')
        ->withDocuments(['Document'])
        ->asRerank();

})->throws(PrismRateLimitedException::class);
