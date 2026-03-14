<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers\Batch;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Batch\BatchListResult;
use Prism\Prism\Providers\Anthropic\Concerns\HandlesBatchResponse;

class ListBatches
{
    use HandlesBatchResponse;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(int $limit = 20, ?string $afterId = null): BatchListResult
    {
        $query = ['limit' => $limit];
        if ($afterId !== null) {
            $query['after_id'] = $afterId;
        }

        $response = $this->client->get('messages/batches', $query);
        $data = $response->json();
        $this->handleResponseErrors($data);

        $jobs = array_map(
            self::mapBatchJob(...),
            data_get($data, 'data', [])
        );

        return new BatchListResult(
            data: $jobs,
            hasMore: (bool) data_get($data, 'has_more', false),
            lastId: data_get($data, 'last_id'),
        );
    }
}
