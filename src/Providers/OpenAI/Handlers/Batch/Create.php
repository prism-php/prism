<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers\Batch;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchRequest;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\Concerns\HandlesBatchResponse;

/**
 * @see https://developers.openai.com/api/docs/guides/batch
 * @see https://developers.openai.com/api/reference/resources/batches/methods/create
 */
class Create
{
    use HandlesBatchResponse;

    private const BATCH_ENDPOINT = '/v1/responses';

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(BatchRequest $request): BatchJob
    {
        if ($request->inputFileId === null) {
            throw new PrismException('OpenAI batch requires an "inputFileId". Upload your JSONL file using the Files API first.');
        }

        /**
         * OpenAI supports batching for: /v1/responses, /v1/chat/completions,
         * /v1/embeddings, /v1/completions, /v1/moderations, /v1/images/generations,
         * /v1/images/edits, and /v1/videos. Only /v1/responses is supported for now.
         */
        $response = $this->client->post('batches', [
            'input_file_id' => $request->inputFileId,
            'endpoint' => self::BATCH_ENDPOINT,
            'completion_window' => $request->providerOptions('completion_window') ?? '24h',
        ]);

        $data = $response->json();
        $this->handleResponseErrors($data);

        return self::mapBatchJob($data);
    }
}
