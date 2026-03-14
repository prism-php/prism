<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\Concerns\HandlesBatchResponse;
use Psr\Http\Message\StreamInterface;

/**
 * @see https://developers.openai.com/api/reference/resources/files
 */
class Files
{
    use HandlesBatchResponse;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function upload(string $content, string $filename, string $purpose): string
    {
        $response = $this->client
            ->asMultipart()
            ->attach('file', $content, $filename)
            ->post('files', [
                'purpose' => $purpose,
            ]);

        $data = $response->json();
        $this->handleResponseErrors($data);

        $fileId = data_get($data, 'id');
        if (! $fileId) {
            throw new PrismException('OpenAI file upload did not return a file ID.');
        }

        return $fileId;
    }

    public function downloadContent(string $fileId): StreamInterface
    {
        $response = $this->client
            ->withOptions(['stream' => true])
            ->get("files/{$fileId}/content");

        return $response->getBody();
    }
}
