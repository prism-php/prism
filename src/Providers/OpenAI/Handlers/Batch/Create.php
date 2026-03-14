<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers\Batch;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchRequest;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsRequestBody;
use Prism\Prism\Providers\OpenAI\Concerns\HandlesBatchResponse;

class Create
{
    use BuildsRequestBody;
    use HandlesBatchResponse;

    private const MAX_REQUESTS = 50_000;

    private const MAX_FILE_BYTES = 200 * 1024 * 1024;

    private const BATCH_ENDPOINT = '/v1/responses';

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(BatchRequest $batchRequest): BatchJob
    {
        if (count($batchRequest->items) > self::MAX_REQUESTS) {
            throw new PrismException(
                sprintf('OpenAI batch limit exceeded: %d requests submitted, maximum is %s.', count($batchRequest->items), number_format(self::MAX_REQUESTS))
            );
        }

        $jsonlContent = $this->buildJsonl($batchRequest);

        $jsonlBytes = strlen($jsonlContent);
        if ($jsonlBytes > self::MAX_FILE_BYTES) {
            throw PrismRequestTooLargeException::make('OpenAI');
        }

        $fileId = $this->uploadFile($jsonlContent);

        $response = $this->client->post('batches', [
            'input_file_id' => $fileId,
            'endpoint' => self::BATCH_ENDPOINT,
            'completion_window' => '24h',
        ]);

        $data = $response->json();
        $this->handleResponseErrors($data);

        return self::mapBatchJob($data);
    }

    protected function buildJsonl(BatchRequest $batchRequest): string
    {
        $lines = [];

        foreach ($batchRequest->items as $item) {
            $lines[] = json_encode([
                'custom_id' => $item->customId,
                'method' => 'POST',
                'url' => self::BATCH_ENDPOINT,
                'body' => $this->buildRequestBody($item->request),
            ], JSON_THROW_ON_ERROR);
        }

        return implode("\n", $lines);
    }

    protected function uploadFile(string $jsonlContent): string
    {
        $response = $this->client
            ->asMultipart()
            ->post('files', [
                [
                    'name' => 'purpose',
                    'contents' => 'batch',
                ],
                [
                    'name' => 'file',
                    'contents' => $jsonlContent,
                    'filename' => 'batch_input.jsonl',
                ],
            ]);

        $data = $response->json();
        $this->handleResponseErrors($data);

        $fileId = data_get($data, 'id');
        if (! $fileId) {
            throw new PrismException('OpenAI file upload did not return a file ID.');
        }

        return $fileId;
    }
}
