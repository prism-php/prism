<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

function createAnthropicTextRequest(string $prompt): TextRequest
{
    return Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withPrompt($prompt)
        ->toRequest();
}

function anthropicFixture(string $name): string
{
    return file_get_contents(__DIR__.'/../../Fixtures/anthropic/'.$name);
}

function createAnthropicBatchRequest(int $count = 2): BatchRequest
{
    $items = [];
    for ($i = 1; $i <= $count; $i++) {
        $items[] = new BatchRequestItem(
            customId: "request-{$i}",
            request: createAnthropicTextRequest("Hello {$i}"),
        );
    }

    return new BatchRequest(items: $items);
}

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-1234'));
});

it('can create a batch', function (): void {
    Http::fake([
        'https://api.anthropic.com/v1/messages/batches' => Http::response(
            anthropicFixture('batch-create-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('anthropic');
    $result = $provider->batch(createAnthropicBatchRequest());

    expect($result)->toBeInstanceOf(BatchJob::class);
    expect($result->id)->toBe('msgbatch_01HkcTjaV5uDC8jWR4ZsDV8d');
    expect($result->status)->toBe(BatchStatus::InProgress);
    expect($result->requestCounts->processing)->toBe(2);
    expect($result->requestCounts->succeeded)->toBe(0);
    expect($result->requestCounts->failed)->toBe(0);
    expect($result->createdAt)->toBe('2024-09-24T18:37:24.100435Z');
    expect($result->expiresAt)->toBe('2024-09-25T18:37:24.100435Z');
    expect($result->endedAt)->toBeNull();
    expect($result->resultsUrl)->toBeNull();
});

it('sends correct request payload structure when creating a batch', function (): void {
    Http::fake([
        'https://api.anthropic.com/v1/messages/batches' => Http::response(
            anthropicFixture('batch-create-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('anthropic');
    $provider->batch(createAnthropicBatchRequest());

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        expect($body)->toHaveKey('requests');
        expect($body['requests'])->toHaveCount(2);
        expect($body['requests'][0])->toHaveKeys(['custom_id', 'params']);
        expect($body['requests'][0]['custom_id'])->toBe('request-1');
        expect($body['requests'][0]['params'])->toHaveKeys(['model', 'messages', 'max_tokens']);
        expect($body['requests'][0]['params']['model'])->toBe('claude-3-5-sonnet-20240620');
        expect($body['requests'][1]['custom_id'])->toBe('request-2');

        return true;
    });
});

it('can retrieve a completed batch', function (): void {
    Http::fake([
        'https://api.anthropic.com/v1/messages/batches/*' => Http::response(
            anthropicFixture('batch-retrieve-completed-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('anthropic');
    $result = $provider->retrieveBatch('msgbatch_01HkcTjaV5uDC8jWR4ZsDV8d');

    expect($result)->toBeInstanceOf(BatchJob::class);
    expect($result->id)->toBe('msgbatch_01HkcTjaV5uDC8jWR4ZsDV8d');
    expect($result->status)->toBe(BatchStatus::Completed);
    expect($result->requestCounts->succeeded)->toBe(2);
    expect($result->requestCounts->processing)->toBe(0);
    expect($result->endedAt)->toBe('2024-09-24T19:00:00.000000Z');
    expect($result->resultsUrl)->toBe(
        'https://api.anthropic.com/v1/messages/batches/msgbatch_01HkcTjaV5uDC8jWR4ZsDV8d/results'
    );
});

it('can list batches', function (): void {
    Http::fake([
        'https://api.anthropic.com/v1/messages/batches*' => Http::response(
            anthropicFixture('batch-list-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('anthropic');
    $result = $provider->listBatches(limit: 10);

    expect($result)->toBeInstanceOf(BatchListResult::class);
    expect($result->data)->toHaveCount(2);
    expect($result->hasMore)->toBeTrue();
    expect($result->lastId)->toBe('msgbatch_02ABC');

    expect($result->data[0]->id)->toBe('msgbatch_01HkcTjaV5uDC8jWR4ZsDV8d');
    expect($result->data[0]->status)->toBe(BatchStatus::Completed);
    expect($result->data[1]->id)->toBe('msgbatch_02ABC');
    expect($result->data[1]->status)->toBe(BatchStatus::InProgress);
});

it('can cancel a batch', function (): void {
    Http::fake([
        'https://api.anthropic.com/v1/messages/batches/*/cancel' => Http::response(
            anthropicFixture('batch-cancel-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('anthropic');
    $result = $provider->cancelBatch('msgbatch_01HkcTjaV5uDC8jWR4ZsDV8d');

    expect($result)->toBeInstanceOf(BatchJob::class);
    expect($result->id)->toBe('msgbatch_01HkcTjaV5uDC8jWR4ZsDV8d');
    expect($result->status)->toBe(BatchStatus::Cancelling);
    expect($result->requestCounts->processing)->toBe(1);
    expect($result->requestCounts->succeeded)->toBe(1);
});

it('can get batch results', function (): void {
    Http::fake([
        'https://api.anthropic.com/v1/messages/batches/msgbatch_01HkcTjaV5uDC8jWR4ZsDV8d/results' => Http::response(
            anthropicFixture('batch-results-1.jsonl'),
            200
        ),
        'https://api.anthropic.com/v1/messages/batches/*' => Http::response(
            anthropicFixture('batch-retrieve-completed-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('anthropic');
    $results = iterator_to_array(
        $provider->getBatchResults('msgbatch_01HkcTjaV5uDC8jWR4ZsDV8d')
    );

    expect($results)->toHaveCount(2);

    expect($results[0])->toBeInstanceOf(BatchResultItem::class);
    expect($results[0]->customId)->toBe('my-first-request');
    expect($results[0]->status)->toBe(BatchResultStatus::Succeeded);
    expect($results[0]->text)->toBe('Hello! How can I assist you today?');
    expect($results[0]->usage->promptTokens)->toBe(10);
    expect($results[0]->usage->completionTokens)->toBe(34);
    expect($results[0]->messageId)->toBe('msg_01FqfsLoHwgeFbguDgpz48m7');
    expect($results[0]->model)->toBe('claude-3-5-sonnet-20240620');

    expect($results[1]->customId)->toBe('my-second-request');
    expect($results[1]->status)->toBe(BatchResultStatus::Succeeded);
    expect($results[1]->text)->toBe('Hello again! Nice to see you.');
    expect($results[1]->usage->promptTokens)->toBe(11);
    expect($results[1]->usage->completionTokens)->toBe(36);
});

it('can get mixed batch results', function (): void {
    Http::fake([
        'https://api.anthropic.com/v1/messages/batches/msgbatch_01HkcTjaV5uDC8jWR4ZsDV8d/results' => Http::response(
            anthropicFixture('batch-results-mixed-1.jsonl'),
            200
        ),
        'https://api.anthropic.com/v1/messages/batches/*' => Http::response(
            anthropicFixture('batch-retrieve-completed-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('anthropic');
    $results = iterator_to_array(
        $provider->getBatchResults('msgbatch_01HkcTjaV5uDC8jWR4ZsDV8d')
    );

    expect($results)->toHaveCount(4);

    expect($results[0]->customId)->toBe('req-success');
    expect($results[0]->status)->toBe(BatchResultStatus::Succeeded);
    expect($results[0]->text)->toBe('Hello!');

    expect($results[1]->customId)->toBe('req-errored');
    expect($results[1]->status)->toBe(BatchResultStatus::Errored);
    expect($results[1]->errorType)->toBe('invalid_request');
    expect($results[1]->errorMessage)->toBe('Invalid model specified.');

    expect($results[2]->customId)->toBe('req-canceled');
    expect($results[2]->status)->toBe(BatchResultStatus::Canceled);

    expect($results[3]->customId)->toBe('req-expired');
    expect($results[3]->status)->toBe(BatchResultStatus::Expired);
});

it('throws when batch results are not ready', function (): void {
    $responseWithoutResults = json_encode([
        'id' => 'msgbatch_01HkcTjaV5uDC8jWR4ZsDV8d',
        'type' => 'message_batch',
        'processing_status' => 'in_progress',
        'request_counts' => [
            'processing' => 2, 'succeeded' => 0, 'errored' => 0,
            'canceled' => 0, 'expired' => 0,
        ],
        'created_at' => '2024-09-24T18:37:24.100435Z',
        'expires_at' => '2024-09-25T18:37:24.100435Z',
        'ended_at' => null,
        'results_url' => null,
    ]);

    Http::fake([
        'https://api.anthropic.com/v1/messages/batches/*' => Http::response($responseWithoutResults, 200),
    ])->preventStrayRequests();

    $provider = Prism::provider('anthropic');
    iterator_to_array($provider->getBatchResults('msgbatch_01HkcTjaV5uDC8jWR4ZsDV8d'));
})->throws(PrismException::class, 'Anthropic batch results are not yet available.');

it('throws when request count exceeds limit', function (): void {
    Http::fake()->preventStrayRequests();

    $items = [];
    for ($i = 0; $i < 100_001; $i++) {
        $items[] = new BatchRequestItem(
            customId: "req-{$i}",
            request: createAnthropicTextRequest('Hello'),
        );
    }

    $provider = Prism::provider('anthropic');
    $provider->batch(new BatchRequest(items: $items));
})->throws(PrismException::class, 'Anthropic batch limit exceeded: 100001 requests submitted, maximum is 100,000.');

it('throws when provider returns an error in response body', function (): void {
    Http::fake([
        'https://api.anthropic.com/v1/messages/batches' => Http::response(
            json_encode([
                'type' => 'error',
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid batch request.',
                ],
            ]),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('anthropic');
    $provider->batch(createAnthropicBatchRequest());
})->throws(PrismException::class, 'Anthropic Error: [invalid_request_error] Invalid batch request.');
