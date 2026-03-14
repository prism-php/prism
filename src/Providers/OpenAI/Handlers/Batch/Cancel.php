<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers\Batch;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Providers\OpenAI\Concerns\HandlesBatchResponse;

class Cancel
{
    use HandlesBatchResponse;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(string $batchId): BatchJob
    {
        $response = $this->client->post("batches/{$batchId}/cancel");
        $data = $response->json();
        $this->handleResponseErrors($data);

        return self::mapBatchJob($data);
    }
}
