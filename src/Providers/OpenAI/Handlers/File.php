<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\File\DeleteResponse;
use Prism\Prism\File\ListResponse;
use Prism\Prism\File\Request;
use Prism\Prism\File\Response;
use Prism\Prism\Providers\OpenAI\Concerns\PreparesFileResponses;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;

class File
{
    use PreparesFileResponses;
    use ValidatesResponse;

    public function __construct(protected PendingRequest $client) {}

    public function uploadFile(Request $request): Response
    {
        $fileHandle = fopen(
            Storage::disk($request->disk())->path(
                $request->path().$request->fileName()
            ),
            'r'
        );
        if (! $fileHandle) {
            throw new PrismException('Failed to open file');
        }
        $response = $this->client
            ->attach('file', $fileHandle, $request->fileName())
            ->post(config('prism.providers.openai.files_endpoint'), [
                'purpose' => $request->purpose(),
            ]);

        fclose($fileHandle);
        $this->validateResponse($response);

        if ($response->status() !== 200) {
            throw new PrismException('Failed to upload file');
        }

        return $this->prepareFileResponse($response->json());
    }

    /**
     * Returns the raw content of the file in a string
     */
    public function downloadFile(Request $request): string
    {
        if (! $request->fileOutputId()) {
            throw new PrismException('File output ID is required');
        }
        $response = $this->client->get(
            config('prism.providers.openai.files_endpoint')."/{$request->fileOutputId()}/content"
        );

        if ($response->status() !== 200) {
            throw new PrismException('Failed to download file');
        }

        return $response->body();
    }

    public function retrieveFile(Request $request): Response
    {
        if (! $request->fileOutputId()) {
            throw new PrismException('File output ID is required');
        }
        $response = $this->client->get(config('prism.providers.openai.files_endpoint')."/{$request->fileOutputId()}");

        if ($response->status() !== 200) {
            throw new PrismException('Failed to retrieve file');
        }

        return $this->prepareFileResponse($response->json());
    }

    public function listFiles(): ListResponse
    {
        $response = $this->client->get(config('prism.providers.openai.files_endpoint'));

        if ($response->status() !== 200) {
            throw new PrismException('Failed to list files');
        }

        return $this->prepareFileListResponse($response->json());
    }

    public function deleteFile(Request $request): DeleteResponse
    {
        if (! $request->fileOutputId()) {
            throw new PrismException('File output ID is required');
        }
        $response = $this->client->delete(config('prism.providers.openai.files_endpoint')."/{$request->fileOutputId()}");

        if ($response->status() !== 200) {
            throw new PrismException('Failed to delete file');
        }

        return $this->prepareDeleteResponse($response->json());
    }
}
