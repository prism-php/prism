<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Batch;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchStatus;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;

require_once __DIR__.'/Helpers.php';

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-1234'));
});

it('can create a batch', function (): void {
    Http::fake([
        'v1/batches' => Http::response(openaiFixture('batch-create-1.json'), 200),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    $result = $provider->batch(createOpenAIBatchRequest(inputFileId: 'file-abc123'));

    expect($result)->toBeInstanceOf(BatchJob::class)
        ->and($result->id)->toBe('batch_abc123')
        ->and($result->status)->toBe(BatchStatus::Validating)
        ->and($result->requestCounts->total)->toBe(2)
        ->and($result->requestCounts->succeeded)->toBe(0)
        ->and($result->requestCounts->failed)->toBe(0)
        ->and($result->outputFileId)->toBeNull()
        ->and($result->errorFileId)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        expect($body['input_file_id'])->toBe('file-abc123')
            ->and($body['endpoint'])->toBe('/v1/responses')
            ->and($body['completion_window'])->toBe('24h');

        return true;
    });
});

it('throws when inputFileId is not provided', function (): void {
    Http::fake()->preventStrayRequests();

    $provider = Prism::provider('openai');
    $provider->batch(createOpenAIBatchRequest());
})->throws(PrismException::class, 'OpenAI batch requires an "inputFileId".');
