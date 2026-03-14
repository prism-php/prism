<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchListResult;
use Prism\Prism\Batch\BatchRequest;
use Prism\Prism\Batch\BatchRequestItem;
use Prism\Prism\Batch\BatchResultItem;
use Prism\Prism\Batch\BatchResultStatus;
use Prism\Prism\Batch\BatchStatus;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Request as TextRequest;

function createOpenAITextRequest(string $prompt): TextRequest
{
    return Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt($prompt)
        ->toRequest();
}

function openaiFixture(string $name): string
{
    return file_get_contents(__DIR__.'/../../Fixtures/openai/'.$name);
}

function createOpenAIBatchRequest(int $count = 2): BatchRequest
{
    $items = [];
    for ($i = 1; $i <= $count; $i++) {
        $items[] = new BatchRequestItem(
            customId: "request-{$i}",
            request: createOpenAITextRequest("Hello {$i}"),
        );
    }

    return new BatchRequest(items: $items);
}

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-1234'));
});

it('can create a batch', function (): void {
    Http::fake(function (Request $request) {
        if (Str::contains($request->url(), '/files') && ! Str::contains($request->url(), '/content')) {
            return Http::response(openaiFixture('batch-file-upload-1.json'), 200);
        }

        if (Str::contains($request->url(), '/batches')) {
            return Http::response(openaiFixture('batch-create-1.json'), 200);
        }

        return Http::response('Not found', 404);
    });

    $provider = Prism::provider('openai');
    $result = $provider->batch(createOpenAIBatchRequest());

    expect($result)->toBeInstanceOf(BatchJob::class);
    expect($result->id)->toBe('batch_abc123');
    expect($result->status)->toBe(BatchStatus::Validating);
    expect($result->requestCounts->total)->toBe(2);
    expect($result->requestCounts->succeeded)->toBe(0);
    expect($result->requestCounts->failed)->toBe(0);
    expect($result->outputFileId)->toBeNull();
    expect($result->errorFileId)->toBeNull();
});

it('sends correct JSONL payload to file upload', function (): void {
    Http::fake(function (Request $request) {
        if (Str::contains($request->url(), '/files') && ! Str::contains($request->url(), '/content')) {
            return Http::response(openaiFixture('batch-file-upload-1.json'), 200);
        }

        if (Str::contains($request->url(), '/batches')) {
            return Http::response(openaiFixture('batch-create-1.json'), 200);
        }

        return Http::response('Not found', 404);
    });

    $provider = Prism::provider('openai');
    $provider->batch(createOpenAIBatchRequest());

    Http::assertSent(function (Request $request): bool {
        if (! Str::contains($request->url(), '/files') || Str::contains($request->url(), '/content')) {
            return false;
        }

        $body = $request->body();
        expect($body)->toContain('batch_input.jsonl');
        expect($body)->toContain('request-1');
        expect($body)->toContain('request-2');
        expect($body)->toContain('gpt-4o');

        return true;
    });

    Http::assertSent(function (Request $request): bool {
        if (! Str::contains($request->url(), '/batches')) {
            return false;
        }

        $body = $request->body();
        expect($body)->toContain('file-abc123');
        expect($body)->toContain('/v1/responses');
        expect($body)->toContain('24h');

        return true;
    });
});

it('can retrieve a completed batch', function (): void {
    Http::fake([
        'https://api.openai.com/v1/batches/*' => Http::response(
            openaiFixture('batch-retrieve-completed-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    $result = $provider->retrieveBatch('batch_abc123');

    expect($result)->toBeInstanceOf(BatchJob::class);
    expect($result->id)->toBe('batch_abc123');
    expect($result->status)->toBe(BatchStatus::Completed);
    expect($result->requestCounts->succeeded)->toBe(2);
    expect($result->requestCounts->total)->toBe(2);
    expect($result->requestCounts->failed)->toBe(0);
    expect($result->outputFileId)->toBe('file-output-xyz');
    expect($result->endedAt)->not->toBeNull();
});

it('can list batches', function (): void {
    Http::fake([
        'https://api.openai.com/v1/batches*' => Http::response(
            openaiFixture('batch-list-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    $result = $provider->listBatches(limit: 10);

    expect($result)->toBeInstanceOf(BatchListResult::class);
    expect($result->data)->toHaveCount(2);
    expect($result->hasMore)->toBeTrue();
    expect($result->lastId)->toBe('batch_def456');

    expect($result->data[0]->id)->toBe('batch_abc123');
    expect($result->data[0]->status)->toBe(BatchStatus::Completed);
    expect($result->data[0]->outputFileId)->toBe('file-output-xyz');

    expect($result->data[1]->id)->toBe('batch_def456');
    expect($result->data[1]->status)->toBe(BatchStatus::InProgress);
});

it('can cancel a batch', function (): void {
    Http::fake([
        'https://api.openai.com/v1/batches/*/cancel' => Http::response(
            openaiFixture('batch-cancel-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    $result = $provider->cancelBatch('batch_abc123');

    expect($result)->toBeInstanceOf(BatchJob::class);
    expect($result->id)->toBe('batch_abc123');
    expect($result->status)->toBe(BatchStatus::Cancelling);
    expect($result->requestCounts->total)->toBe(2);
});

it('can get batch results', function (): void {
    Http::fake(function (Request $request) {
        if (Str::contains($request->url(), '/files/') && Str::contains($request->url(), '/content')) {
            return Http::response(openaiFixture('batch-results-1.jsonl'), 200);
        }

        if (Str::contains($request->url(), '/batches/')) {
            return Http::response(openaiFixture('batch-retrieve-completed-1.json'), 200);
        }

        return Http::response('Not found', 404);
    });

    $provider = Prism::provider('openai');
    $results = iterator_to_array($provider->getBatchResults('batch_abc123'));

    expect($results)->toHaveCount(2);

    expect($results[0])->toBeInstanceOf(BatchResultItem::class);
    expect($results[0]->customId)->toBe('my-first-request');
    expect($results[0]->status)->toBe(BatchResultStatus::Succeeded);
    expect($results[0]->text)->toBe('Hello! How can I help you today?');
    expect($results[0]->usage->promptTokens)->toBe(15);
    expect($results[0]->usage->completionTokens)->toBe(25);
    expect($results[0]->messageId)->toBe('resp_001');
    expect($results[0]->model)->toBe('gpt-4o-2024-08-06');

    expect($results[1]->customId)->toBe('my-second-request');
    expect($results[1]->status)->toBe(BatchResultStatus::Succeeded);
    expect($results[1]->text)->toBe('Nice to meet you!');
});

it('can get mixed batch results', function (): void {
    Http::fake(function (Request $request) {
        if (Str::contains($request->url(), '/files/') && Str::contains($request->url(), '/content')) {
            return Http::response(openaiFixture('batch-results-mixed-1.jsonl'), 200);
        }

        if (Str::contains($request->url(), '/batches/')) {
            return Http::response(openaiFixture('batch-retrieve-completed-1.json'), 200);
        }

        return Http::response('Not found', 404);
    });

    $provider = Prism::provider('openai');
    $results = iterator_to_array($provider->getBatchResults('batch_abc123'));

    expect($results)->toHaveCount(3);

    expect($results[0]->customId)->toBe('req-success');
    expect($results[0]->status)->toBe(BatchResultStatus::Succeeded);
    expect($results[0]->text)->toBe('Hello!');

    expect($results[1]->customId)->toBe('req-http-error');
    expect($results[1]->status)->toBe(BatchResultStatus::Errored);
    expect($results[1]->errorType)->toBe('http_error');

    expect($results[2]->customId)->toBe('req-expired');
    expect($results[2]->status)->toBe(BatchResultStatus::Expired);
    expect($results[2]->errorType)->toBe('batch_expired');
    expect($results[2]->errorMessage)->toBe('This batch has expired.');
});

it('throws when batch results are not ready', function (): void {
    $responseWithoutOutput = json_encode([
        'id' => 'batch_abc123',
        'object' => 'batch',
        'endpoint' => '/v1/responses',
        'status' => 'in_progress',
        'output_file_id' => null,
        'error_file_id' => null,
        'created_at' => 1727200644,
        'expires_at' => 1727287044,
        'completed_at' => null,
        'request_counts' => ['total' => 2, 'completed' => 0, 'failed' => 0],
    ]);

    Http::fake([
        'https://api.openai.com/v1/batches/*' => Http::response($responseWithoutOutput, 200),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    iterator_to_array($provider->getBatchResults('batch_abc123'));
})->throws(PrismException::class, 'OpenAI batch results are not yet available.');

it('throws when request count exceeds limit', function (): void {
    Http::fake()->preventStrayRequests();

    $items = [];
    for ($i = 0; $i < 50_001; $i++) {
        $items[] = new BatchRequestItem(
            customId: "req-{$i}",
            request: createOpenAITextRequest('Hello'),
        );
    }

    $provider = Prism::provider('openai');
    $provider->batch(new BatchRequest(items: $items));
})->throws(PrismException::class, 'OpenAI batch limit exceeded: 50001 requests submitted, maximum is 50,000.');

it('throws when provider returns an error in response body', function (): void {
    Http::fake([
        'https://api.openai.com/v1/batches/*' => Http::response(
            json_encode([
                'error' => [
                    'message' => 'Batch not found.',
                    'type' => 'not_found_error',
                    'code' => 'batch_not_found',
                ],
            ]),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    $provider->retrieveBatch('batch_nonexistent');
})->throws(PrismException::class, 'OpenAI Error: [not_found_error] Batch not found.');
