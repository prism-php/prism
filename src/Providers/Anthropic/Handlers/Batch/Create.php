<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers\Batch;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchRequest;
use Prism\Prism\Batch\BatchRequestItem;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\Anthropic\Concerns\HandlesBatchResponse;
use Prism\Prism\Providers\Anthropic\Handlers\Text;

class Create
{
    use HandlesBatchResponse;

    private const MAX_REQUESTS = 100_000;

    private const MAX_PAYLOAD_BYTES = 256 * 1024 * 1024;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(BatchRequest $batchRequest): BatchJob
    {
        if (count($batchRequest->items) > self::MAX_REQUESTS) {
            throw new PrismException(
                sprintf('Anthropic batch limit exceeded: %d requests submitted, maximum is %s.', count($batchRequest->items), number_format(self::MAX_REQUESTS))
            );
        }

        $requests = array_map(fn (BatchRequestItem $item): array => [
            'custom_id' => $item->customId,
            'params' => Text::buildHttpRequestPayload($item->request),
        ], $batchRequest->items);

        $payload = ['requests' => $requests];

        $payloadSize = strlen((string) json_encode($payload));
        if ($payloadSize > self::MAX_PAYLOAD_BYTES) {
            throw PrismRequestTooLargeException::make('Anthropic');
        }

        $response = $this->client->post('messages/batches', $payload);
        $data = $response->json();
        $this->handleResponseErrors($data);

        return self::mapBatchJob($data);
    }
}
