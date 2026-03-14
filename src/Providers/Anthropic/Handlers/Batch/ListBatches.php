<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers\Batch;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Batch\BatchListResult;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Anthropic\Concerns\HandlesBatchResponse;

/**
 * @see https://platform.claude.com/docs/en/api/beta/messages/batches/list
 */
class ListBatches
{
    use HandlesBatchResponse;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    /**
     * Lists batch jobs with pagination support.
     *
     * @param  array<string, string>  $params
     *
     * @throws ConnectionException
     * @throws PrismException
     */
    public function handle(?array $params = null): BatchListResult
    {
        $query = Arr::whereNotNull([
            'after_id' => data_get($params, 'after_id'),
            'before_id' => data_get($params, 'before_id'),
            'limit' => data_get($params, 'limit'),
        ]);

        // Set query to null if it's empty to avoid sending an empty query string
        if (empty($query)) {
            $query = null;
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
